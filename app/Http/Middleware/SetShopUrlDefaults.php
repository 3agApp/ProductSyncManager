<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class SetShopUrlDefaults
{
    /**
     * Set the default URL parameters for shop-based routes.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($currentShop = $request->user()?->currentShop) {
            URL::defaults([
                'current_shop' => $currentShop->slug,
                'shop' => $currentShop->slug,
            ]);
        }

        return $next($request);
    }
}
