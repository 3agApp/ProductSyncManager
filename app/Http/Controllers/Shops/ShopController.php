<?php

namespace App\Http\Controllers\Shops;

use App\Actions\Shops\CreateShop;
use App\Actions\Shops\DeleteShop;
use App\Enums\ShopRole;
use App\Enums\ShopType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shops\DeleteShopRequest;
use App\Http\Requests\Shops\SaveShopRequest;
use App\Models\Membership;
use App\Models\Shop;
use App\Models\ShopInvitation;
use App\Models\User;
use App\Support\ShopHealth;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ShopController extends Controller
{
    /**
     * Display a listing of the user's shops.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('shops/index', [
            'shops' => $user->toUserShops(includeCurrent: true),
        ]);
    }

    /**
     * Store a newly created shop.
     */
    public function store(SaveShopRequest $request, CreateShop $createShop): RedirectResponse
    {
        $shop = $createShop->handle(
            $request->user(),
            $request->validated('name'),
            ShopType::tryFrom((string) $request->validated('type')) ?? ShopType::Wordpress,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Shop created.')]);

        return to_route('shops.edit', ['shop' => $shop->slug]);
    }

    /**
     * Show the shop edit page.
     */
    public function edit(Request $request, Shop $shop): Response
    {
        $user = $request->user();
        $permissions = $user->toShopPermissions($shop);
        $health = new ShopHealth($shop, $permissions);

        return Inertia::render('shops/edit', [
            'shop' => [
                'id' => $shop->id,
                'name' => $shop->name,
                'slug' => $shop->slug,
                'type' => $shop->type->value,
                'typeLabel' => $shop->type->label(),
                'pricesIncludeTax' => $shop->prices_include_tax,
                'taxRate' => rtrim(rtrim($shop->tax_rate, '0'), '.'),
                'currency' => $shop->currency,
            ],
            'members' => $shop->members()->get()->map(function (User $member) {
                /** @var Membership $membership */
                $membership = $member->getRelation('pivot');

                return [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'avatar' => $member->avatar ?? null,
                    'role' => $membership->role->value,
                    'role_label' => $membership->role->label(),
                ];
            }),
            'invitations' => $shop->invitations()
                ->whereNull('accepted_at')
                ->oldest()
                ->get()
                ->map(fn (ShopInvitation $invitation) => [
                    'code' => $invitation->code,
                    'email' => $invitation->email,
                    'role' => $invitation->role->value,
                    'role_label' => $invitation->role->label(),
                    'created_at' => $invitation->created_at->toISOString(),
                    'sent_at_diff' => $invitation->created_at->diffForHumans(),
                    // Without "ago" or "from now", so the page can say
                    // "Expires in 2 days" or "Expired 3 hours ago".
                    'expires_at_diff' => $invitation->expires_at?->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE),
                    'is_expired' => $invitation->isExpired(),
                ]),
            'integrations' => fn () => $health->integrations(),
            'attention' => fn () => $health->attention(),
            'permissions' => $permissions,
            'availableRoles' => ShopRole::assignable(),
        ]);
    }

    /**
     * Update the specified shop.
     */
    public function update(SaveShopRequest $request, Shop $shop): RedirectResponse
    {
        Gate::authorize('update', $shop);

        $shop = DB::transaction(function () use ($request, $shop) {
            $shop = Shop::whereKey($shop->id)->lockForUpdate()->firstOrFail();

            $shop->update([
                'name' => $request->validated('name'),
                'type' => ShopType::tryFrom((string) $request->validated('type')) ?? $shop->type,
                ...$request->safe()->only(['prices_include_tax', 'tax_rate', 'currency']),
            ]);

            return $shop;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Shop updated.')]);

        return to_route('shops.edit', ['shop' => $shop->slug]);
    }

    /**
     * Switch the user's current shop.
     */
    public function switch(Request $request, Shop $shop): RedirectResponse
    {
        abort_unless($request->user()->belongsToShop($shop), 403);

        $request->user()->switchShop($shop);

        return back();
    }

    /**
     * Leave the specified shop.
     */
    public function leave(Request $request, Shop $shop): RedirectResponse
    {
        Gate::authorize('leave', $shop);

        $user = $request->user();

        $shop->memberships()
            ->where('user_id', $user->id)
            ->delete();

        if ($user->isCurrentShop($shop)) {
            $user->switchToFallbackShop($shop);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('You left the shop ":name"', ['name' => $shop->name])]);

        return to_route($user->currentShop ? 'shops.index' : 'onboarding');
    }

    /**
     * Delete the specified shop.
     */
    public function destroy(DeleteShopRequest $request, Shop $shop, DeleteShop $deleteShop): RedirectResponse
    {
        $deleteShop->handle($shop);

        $user = $request->user()->refresh();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Shop deleted.')]);

        return to_route($user->currentShop ? 'shops.index' : 'onboarding');
    }
}
