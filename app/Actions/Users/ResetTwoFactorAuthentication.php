<?php

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class ResetTwoFactorAuthentication
{
    /**
     * Turn off two-factor authentication and remove every passkey, so a
     * locked-out user can sign in with their password again.
     */
    public function handle(User $user): void
    {
        DB::transaction(function () use ($user) {
            $user->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();

            $user->passkeys()->delete();
        });
    }
}
