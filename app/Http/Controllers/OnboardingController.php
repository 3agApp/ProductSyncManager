<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingController extends Controller
{
    /**
     * Show the first-run page for a user who does not belong to a shop yet.
     *
     * Users are no longer given a shop when they register, so this is where
     * they create one or go and accept an invitation.
     */
    public function __invoke(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($shop = $user->currentShop ?? $user->fallbackShop()) {
            return to_route('dashboard', ['current_shop' => $shop->slug]);
        }

        return Inertia::render('onboarding');
    }
}
