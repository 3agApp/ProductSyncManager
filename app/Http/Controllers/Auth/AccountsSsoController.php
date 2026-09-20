<?php

namespace App\Http\Controllers\Auth;

use App\Http\Responses\Concerns\RedirectsToCurrentShop;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use ThreeAg\AccountsOidc\Http\Controllers\AccountsSsoController as BaseAccountsSsoController;

/**
 * Signing in is the suite's, not this app's: the flow, the user matching and
 * the claim checks all live in 3agapp/accounts-oidc. What is left here is
 * the part only ProductSync can answer.
 */
class AccountsSsoController extends BaseAccountsSsoController
{
    use RedirectsToCurrentShop;

    /**
     * An invitation code rides along in the session because the round trip
     * through Accounts discards the query string it arrived in.
     */
    protected function invitationSessionKey(): string
    {
        return 'shop_invitation';
    }

    /**
     * Send the user to the dashboard of whichever shop they are working in.
     */
    protected function redirectPathAfterSignIn(Request $request): string
    {
        return $this->redirectPathForCurrentShop($request, '/dashboard');
    }

    /**
     * Send the guest back to the homepage with a toast explaining the failure.
     */
    protected function failed(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return redirect('/');
    }
}
