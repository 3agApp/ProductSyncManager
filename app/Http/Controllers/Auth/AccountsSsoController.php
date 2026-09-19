<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Responses\Concerns\RedirectsToCurrentShop;
use App\Models\User;
use App\Services\Auth\AccountsOidc;
use App\Services\Auth\AccountsUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Throwable;

class AccountsSsoController extends Controller
{
    use RedirectsToCurrentShop;

    public function __construct(private readonly AccountsOidc $accounts)
    {
        //
    }

    /**
     * Send the guest to 3AG Accounts for login.
     *
     * Building the URL reaches out to the issuer for its discovery document,
     * so an unreachable or misconfigured Accounts would otherwise take the
     * whole login route down with it. Report it and send the guest back to
     * the homepage with a message, as the callback does.
     */
    public function redirect(Request $request): RedirectResponse
    {
        try {
            return redirect()->away($this->accounts->authorizeUrl($request));
        } catch (Throwable $exception) {
            report($exception);

            return $this->failed(__('Could not sign in with 3AG Accounts. Please try again.'));
        }
    }

    /**
     * Finish the OIDC callback and start a local ProductSync session.
     */
    public function callback(Request $request): RedirectResponse
    {
        try {
            $accountsUser = $this->accounts->user($request);
        } catch (Throwable $exception) {
            report($exception);

            return $this->failed(__('Could not sign in with 3AG Accounts. Please try again.'));
        }

        $email = $accountsUser->email;

        if (! filled($email)) {
            return $this->failed(__('3AG Accounts did not return an email address.'));
        }

        $user = $this->link($accountsUser, Str::lower($email));

        Auth::login($user, remember: true);

        $request->session()->regenerate();

        $invitation = $request->session()->pull('shop_invitation');

        if (is_string($invitation) && $invitation !== '') {
            return redirect()->route('invitations.index');
        }

        return redirect()->intended(
            $this->redirectPathForCurrentShop($request, '/dashboard'),
        );
    }

    /**
     * Find the local user behind the Accounts subject, or create one.
     *
     * The subject is matched first so an address changed in Accounts follows
     * the same person here. Email is the fallback that adopts the accounts
     * that existed before single sign-on.
     */
    private function link(AccountsUser $accountsUser, string $email): User
    {
        $user = User::query()->where('sso_id', $accountsUser->id)->first()
            ?? User::query()->where('email', $email)->first();

        // forceFill throughout: email_verified_at is not mass assignable, and
        // Accounts has already verified the address either way.
        if (! $user) {
            $user = new User;

            $user->forceFill([
                'name' => $accountsUser->name ?: Str::before($email, '@'),
                // Nothing signs in with this, but the column is not nullable
                // and a value no one holds is safer than a shared placeholder.
                'password' => Hash::make(Str::password(32)),
            ]);
        }

        $user->forceFill([
            'sso_id' => $accountsUser->id,
            'name' => $accountsUser->name ?: $user->name,
            'email' => $email,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        return $user;
    }

    /**
     * Send the guest back to the homepage with a toast explaining the failure.
     */
    private function failed(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return redirect('/');
    }
}
