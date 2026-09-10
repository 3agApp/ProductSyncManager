<?php

namespace App\Http\Responses\Concerns;

use App\Models\Shop;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

trait RedirectsToCurrentShop
{
    protected function redirectPathForCurrentShop(Request $request, string $redirect): string
    {
        $shop = $this->currentShop($request);

        URL::defaults(['current_shop' => $shop->slug]);

        return "/{$shop->slug}{$redirect}";
    }

    protected function currentShop(Request $request): Shop
    {
        $user = $request->user();

        abort_if(! $user, 403);

        $shop = $user->currentShop ?? $user->personalShop();

        abort_if(! $shop, 403);

        return $shop;
    }
}
