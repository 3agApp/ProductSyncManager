<?php

namespace App\Http\Controllers\Shops;

use App\Enums\ShopRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shops\CreateShopInvitationRequest;
use App\Http\Requests\Shops\RespondToShopInvitationRequest;
use App\Models\Shop;
use App\Models\ShopInvitation;
use App\Notifications\Shops\ShopInvitation as ShopInvitationNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;

class ShopInvitationController extends Controller
{
    /**
     * List the invitations waiting on the authenticated user.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('invitations/index', [
            'invitations' => ShopInvitation::query()
                ->pendingFor($request->user()->email)
                ->with(['inviter', 'shop'])
                ->latest()
                ->get()
                ->map(fn (ShopInvitation $invitation) => [
                    'code' => $invitation->code,
                    'inviterName' => $invitation->inviter->name,
                    'roleLabel' => $invitation->role->label(),
                    'expiresAt' => $invitation->expires_at?->toIso8601String(),
                    'shop' => [
                        'name' => $invitation->shop->name,
                        'slug' => $invitation->shop->slug,
                    ],
                ]),
        ]);
    }

    /**
     * Store a newly created invitation.
     */
    public function store(CreateShopInvitationRequest $request, Shop $shop): RedirectResponse
    {
        Gate::authorize('inviteMember', $shop);

        $invitation = $shop->invitations()->create([
            'email' => $request->validated('email'),
            'role' => ShopRole::from($request->validated('role')),
            'invited_by' => $request->user()->id,
            'expires_at' => now()->addDays(3),
        ]);

        Notification::route('mail', $invitation->email)
            ->notify(new ShopInvitationNotification($invitation));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent.')]);

        return to_route('shops.edit', ['shop' => $shop->slug]);
    }

    /**
     * Cancel the specified invitation.
     */
    public function destroy(Shop $shop, ShopInvitation $invitation): RedirectResponse
    {
        abort_unless($invitation->shop_id === $shop->id, 404);

        Gate::authorize('cancelInvitation', $shop);

        $invitation->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation cancelled.')]);

        return to_route('shops.edit', ['shop' => $shop->slug]);
    }

    /**
     * Accept the invitation.
     */
    public function accept(RespondToShopInvitationRequest $request, ShopInvitation $invitation): RedirectResponse
    {
        $user = $request->user();

        DB::transaction(function () use ($user, $invitation) {
            $shop = $invitation->shop;

            $shop->memberships()->firstOrCreate(
                ['user_id' => $user->id],
                ['role' => $invitation->role],
            );

            $invitation->update(['accepted_at' => now()]);

            $user->switchShop($shop);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation accepted.')]);

        return to_route('dashboard');
    }

    /**
     * Decline the invitation.
     */
    public function decline(RespondToShopInvitationRequest $request, ShopInvitation $invitation): RedirectResponse
    {
        $invitation->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation declined.')]);

        $morePending = ShopInvitation::query()->pendingFor($request->user()->email)->exists();

        if ($morePending) {
            return to_route('invitations.index');
        }

        return to_route($request->user()->currentShop ? 'dashboard' : 'onboarding');
    }
}
