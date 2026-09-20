<?php

namespace App\Http\Controllers;

use App\Http\Responses\Concerns\RedirectsToCurrentShop;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DashboardRedirectController extends Controller
{
    use RedirectsToCurrentShop;

    /**
     * Send someone at /dashboard to the dashboard they actually have.
     *
     * Every real dashboard lives under a shop, so the bare path cannot be
     * one. It exists because config('fortify.home') is a fixed string that
     * Fortify hands out on its own: an already verified user who opens the
     * verification prompt, or asks for another verification mail, is
     * redirected there and would otherwise land on a 404.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        return redirect($this->redirectPathForCurrentShop($request, '/dashboard'));
    }
}
