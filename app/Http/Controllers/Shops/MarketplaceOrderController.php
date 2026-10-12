<?php

namespace App\Http\Controllers\Shops;

use App\Enums\Marketplace;
use App\Enums\MarketplaceOrderStatus;
use App\Http\Controllers\Controller;
use App\Jobs\ImportNaldaOrders;
use App\Jobs\ImportTemuOrders;
use App\Models\MarketplaceOrder;
use App\Models\Shop;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class MarketplaceOrderController extends Controller
{
    /**
     * Import a marketplace order into WooCommerce again.
     *
     * Only an order that failed, or whose import was cut off, can be sent
     * again. For a cut-off one the page asks the person to check WooCommerce
     * first, since the order may already be there.
     */
    public function retry(Shop $shop, MarketplaceOrder $marketplaceOrder): RedirectResponse
    {
        Gate::authorize('manageSync', $shop);

        if (! $marketplaceOrder->status->needsAttention()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('There is nothing to retry.')]);

            return back();
        }

        $marketplaceOrder->update(['status' => MarketplaceOrderStatus::Pending, 'message' => null]);

        match ($marketplaceOrder->marketplace) {
            Marketplace::Temu => $shop->temuConnection !== null && ImportTemuOrders::canImport($shop->temuConnection)
                ? ImportTemuOrders::dispatch($shop->id) : null,
            Marketplace::Nalda => $shop->naldaConnection !== null && ImportNaldaOrders::canImport($shop->naldaConnection)
                ? ImportNaldaOrders::dispatch($shop->id) : null,
        };

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The order will be imported again.')]);

        return back();
    }
}
