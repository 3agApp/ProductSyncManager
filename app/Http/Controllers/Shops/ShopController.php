<?php

namespace App\Http\Controllers\Shops;

use App\Actions\Shops\CreateShop;
use App\Enums\ShopRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shops\DeleteShopRequest;
use App\Http\Requests\Shops\SaveShopRequest;
use App\Models\Membership;
use App\Models\Shop;
use App\Models\User;
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
        $shop = $createShop->handle($request->user(), $request->validated('name'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Shop created.')]);

        return to_route('shops.edit', ['shop' => $shop->slug]);
    }

    /**
     * Show the shop edit page.
     */
    public function edit(Request $request, Shop $shop): Response
    {
        $user = $request->user();

        return Inertia::render('shops/edit', [
            'shop' => [
                'id' => $shop->id,
                'name' => $shop->name,
                'slug' => $shop->slug,
                'isPersonal' => $shop->is_personal,
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
                ->get()
                ->map(fn ($invitation) => [
                    'code' => $invitation->code,
                    'email' => $invitation->email,
                    'role' => $invitation->role->value,
                    'role_label' => $invitation->role->label(),
                    'created_at' => $invitation->created_at->toISOString(),
                ]),
            'permissions' => $user->toShopPermissions($shop),
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

            $shop->update(['name' => $request->validated('name')]);

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

        $fallbackShop = $user->isCurrentShop($shop)
            ? $user->fallbackShop($shop)
            : null;

        $shop->memberships()
            ->where('user_id', $user->id)
            ->delete();

        if ($fallbackShop) {
            $user->switchShop($fallbackShop);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('You left the shop ":name"', ['name' => $shop->name])]);

        return to_route('shops.index');
    }

    /**
     * Delete the specified shop.
     */
    public function destroy(DeleteShopRequest $request, Shop $shop): RedirectResponse
    {
        $user = $request->user();
        $fallbackShop = $user->isCurrentShop($shop)
            ? $user->fallbackShop($shop)
            : null;

        DB::transaction(function () use ($user, $shop) {
            User::where('current_shop_id', $shop->id)
                ->where('id', '!=', $user->id)
                ->each(fn (User $affectedUser) => $affectedUser->switchShop($affectedUser->personalShop()));

            $shop->invitations()->delete();
            $shop->memberships()->delete();
            $shop->delete();
        });

        if ($fallbackShop) {
            $user->switchShop($fallbackShop);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Shop deleted.')]);

        return to_route('shops.index');
    }
}
