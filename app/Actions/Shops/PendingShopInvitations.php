<?php

namespace App\Actions\Shops;

use App\Models\ShopInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class PendingShopInvitations
{
    /**
     * Get the invitations still waiting for the user's answer.
     *
     * Invitations are addressed to an email rather than an account, so they
     * are matched on the user's email, ignoring case. Accepted and expired
     * invitations are left out.
     *
     * @return Builder<ShopInvitation>
     */
    public function query(User $user): Builder
    {
        return ShopInvitation::query()
            ->whereRaw('LOWER(email) = ?', [strtolower($user->email)])
            ->whereNull('accepted_at')
            ->where(fn (Builder $query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>=', now()));
    }
}
