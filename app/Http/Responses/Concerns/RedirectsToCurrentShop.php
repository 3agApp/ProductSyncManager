<?php

namespace App\Http\Responses\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

trait RedirectsToCurrentShop
{
    /**
     * Get the path to the redirect under the user's current shop, or to
     * onboarding when the user does not belong to a shop yet.
     */
    protected function redirectPathForCurrentShop(Request $request, string $redirect): string
    {
        $user = $request->user();

        abort_if(! $user, 403);

        $shop = $user->currentShop ?? $user->fallbackShop();

        if (! $shop) {
            return route('onboarding', absolute: false);
        }

        URL::defaults(['current_shop' => $shop->slug]);

        return "/{$shop->slug}{$redirect}";
    }
}
