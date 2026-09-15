<?php

namespace App\Http\Controllers\Shops;

use App\Enums\ShopRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shops\UpdateShopMemberRequest;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class ShopMemberController extends Controller
{
    /**
     * Update the specified shop member's role.
     */
    public function update(UpdateShopMemberRequest $request, Shop $shop, User $user): RedirectResponse
    {
        Gate::authorize('updateMember', $shop);

        $newRole = ShopRole::from($request->validated('role'));

        $shop->memberships()
            ->where('user_id', $user->id)
            ->firstOrFail()
            ->update(['role' => $newRole]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member role updated.')]);

        return to_route('shops.edit', ['shop' => $shop->slug]);
    }

    /**
     * Remove the specified shop member.
     */
    public function destroy(Shop $shop, User $user): RedirectResponse
    {
        Gate::authorize('removeMember', $shop);

        abort_if($shop->owner()?->is($user), 403, __('The shop owner cannot be removed.'));

        $shop->memberships()
            ->where('user_id', $user->id)
            ->delete();

        if ($user->isCurrentShop($shop)) {
            $user->switchToFallbackShop($shop);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member removed.')]);

        return to_route('shops.edit', ['shop' => $shop->slug]);
    }
}
