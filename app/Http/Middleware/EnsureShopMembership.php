<?php

namespace App\Http\Middleware;

use App\Enums\ShopRole;
use App\Models\Shop;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureShopMembership
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $minimumRole = null): Response
    {
        [$user, $shop] = [$request->user(), $this->shop($request)];

        abort_if(! $user || ! $shop || ! $user->belongsToShop($shop), 403);

        $this->ensureShopMemberHasRequiredRole($user, $shop, $minimumRole);

        if ($request->route('current_shop') && ! $user->isCurrentShop($shop)) {
            $user->switchShop($shop);
        }

        return $next($request);
    }

    /**
     * Ensure the given user has at least the given role, if applicable.
     */
    protected function ensureShopMemberHasRequiredRole(User $user, Shop $shop, ?string $minimumRole): void
    {
        if ($minimumRole === null) {
            return;
        }

        $role = $user->shopRole($shop);

        $requiredRole = ShopRole::tryFrom($minimumRole);

        abort_if(
            $requiredRole === null ||
            $role === null ||
            ! $role->isAtLeast($requiredRole),
            403,
        );
    }

    /**
     * Get the shop associated with the request.
     */
    protected function shop(Request $request): ?Shop
    {
        $shop = $request->route('current_shop') ?? $request->route('shop');

        if (is_string($shop)) {
            $shop = Shop::where('slug', $shop)->first();
        }

        return $shop;
    }
}
