<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

class Impersonation
{
    /**
     * The session key holding the id of the admin who is impersonating.
     */
    private const string SESSION_KEY = 'impersonator_id';

    /**
     * Sign the admin in as the given user, remembering who to switch back to.
     */
    public function start(User $impersonator, User $user): void
    {
        session()->put(self::SESSION_KEY, $impersonator->id);

        $this->login($user);
    }

    /**
     * Determine if the current session is an admin signed in as someone else.
     */
    public function isImpersonating(): bool
    {
        return session()->has(self::SESSION_KEY);
    }

    /**
     * Sign back in as the admin who started impersonating.
     *
     * Returns null, leaving the session as it is, when there is no admin to
     * return to -- including when that user is no longer the admin.
     */
    public function stop(): ?User
    {
        $impersonatorId = session()->pull(self::SESSION_KEY);

        $impersonator = $impersonatorId ? User::whereKey($impersonatorId)->first() : null;

        if (! $impersonator?->isAdmin()) {
            return null;
        }

        $this->login($impersonator);

        return $impersonator;
    }

    /**
     * Sign in as the user within the current session.
     *
     * The admin panel checks the session's stored password hash on every
     * request and logs out on a mismatch, so the hash left by whoever was
     * signed in before is cleared for it to store the new user's.
     */
    private function login(User $user): void
    {
        session()->forget('password_hash_'.Auth::getDefaultDriver());

        Auth::guard('web')->login($user);
    }
}
