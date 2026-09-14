<?php

namespace App\Http\Controllers;

use App\Concerns\PresentsProducts;
use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    use PresentsProducts;

    /**
     * The number of recently touched products shown on the overview.
     */
    private const int RECENT_PRODUCTS = 5;

    /**
     * Show an overview of the shop's catalog.
     *
     * The full product table lives on its own page; this one carries what a
     * member wants at a glance -- the figures, anything a sync is doing, and
     * the handful of products that changed most recently.
     */
    public function __invoke(Request $request, Shop $currentShop): Response
    {
        $recent = $currentShop->products()
            ->with(['brand', 'categories'])
            ->latest('updated_at')
            ->latest('id')
            ->limit(self::RECENT_PRODUCTS)
            ->get();

        $categoryPaths = Category::pathsFor($currentShop);

        return Inertia::render('dashboard', [
            'stats' => $this->stats($currentShop),
            'recentProducts' => $recent
                ->map(fn (Product $product) => $this->productPayload($product, $categoryPaths))
                ->all(),
            'lowStockThreshold' => Product::LOW_STOCK_THRESHOLD,
            'permissions' => $request->user()->toShopPermissions($currentShop),
            'activeSyncRun' => fn () => $this->activeSyncRun($currentShop),
        ]);
    }

    /**
     * Get the catalog statistics for the shop.
     *
     * @return array{total: int, active: int, draft: int, archived: int, lowStock: int, outOfStock: int, inventoryValue: float, brands: int, categories: int}
     */
    private function stats(Shop $shop): array
    {
        $stats = (array) $shop->products()
            ->toBase()
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as active', [ProductStatus::Active->value])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as draft', [ProductStatus::Draft->value])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as archived', [ProductStatus::Archived->value])
            ->selectRaw('sum(case when stock between 1 and ? then 1 else 0 end) as low_stock', [Product::LOW_STOCK_THRESHOLD])
            ->selectRaw('sum(case when stock = 0 then 1 else 0 end) as out_of_stock')
            ->selectRaw('sum(price * stock) as inventory_value')
            ->first();

        return [
            'total' => (int) ($stats['total'] ?? 0),
            'active' => (int) ($stats['active'] ?? 0),
            'draft' => (int) ($stats['draft'] ?? 0),
            'archived' => (int) ($stats['archived'] ?? 0),
            'lowStock' => (int) ($stats['low_stock'] ?? 0),
            'outOfStock' => (int) ($stats['out_of_stock'] ?? 0),
            'inventoryValue' => round((float) ($stats['inventory_value'] ?? 0), 2),
            'brands' => $shop->brands()->count(),
            'categories' => $shop->categories()->count(),
        ];
    }
}
