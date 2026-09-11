<?php

namespace App\Http\Controllers;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopInvitation;
use App\Models\SyncRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * The number of products shown per page.
     */
    private const int PER_PAGE = 10;

    public function __invoke(Request $request, Shop $currentShop): Response|RedirectResponse
    {
        $search = $request->string('search')->trim()->toString();
        $status = ProductStatus::tryFrom($request->string('status')->toString());

        $products = $currentShop->products()
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('sku', 'like', "%{$search}%")))
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->latest()
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        if ($products->isEmpty() && $products->currentPage() > 1) {
            return redirect($products->url($products->lastPage()));
        }

        return Inertia::render('dashboard', [
            'products' => $products->through(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'gtin' => $product->gtin,
                'description' => $product->description,
                'shortDescription' => $product->short_description,
                'brands' => $product->brands ?? [],
                'categories' => $product->categories ?? [],
                'imageUrl' => $product->image_url,
                'galleryImageUrls' => $product->gallery_image_urls ?? [],
                'permalink' => $product->permalink,
                'price' => $product->price,
                'stock' => $product->stock,
                'status' => $product->status->value,
                'statusLabel' => $product->status->label(),
                'updatedAt' => $product->updated_at?->toISOString(),
                'updatedAtDiff' => $product->updated_at?->diffForHumans(),
            ]),
            'stats' => $this->stats($currentShop),
            'filters' => [
                'search' => $search,
                'status' => $status?->value,
            ],
            'statuses' => ProductStatus::options(),
            'lowStockThreshold' => Product::LOW_STOCK_THRESHOLD,
            'permissions' => $request->user()->toShopPermissions($currentShop),
            'pendingInvitations' => $this->pendingInvitations($request),
            'activeSyncRun' => fn () => $this->activeSyncRun($currentShop),
        ]);
    }

    /**
     * Get the in-flight sync for the shop, if there is one.
     *
     * This is deliberately smaller than the payload the sync settings page
     * uses: the dashboard only shows a progress banner, and it is reloaded
     * on a timer while a sync runs.
     *
     * @return array{id: int, status: string, progress: int|null, processedProducts: int, totalProducts: int|null}|null
     */
    private function activeSyncRun(Shop $shop): ?array
    {
        $run = $shop->syncRuns()->active()->latest('id')->first();

        if (! $run instanceof SyncRun) {
            return null;
        }

        return [
            'id' => $run->id,
            'status' => $run->status->value,
            'progress' => $run->progress(),
            'processedProducts' => $run->processed_products,
            'totalProducts' => $run->total_products,
        ];
    }

    /**
     * Get the catalog statistics for the shop.
     *
     * @return array{total: int, active: int, draft: int, lowStock: int, outOfStock: int, inventoryValue: float}
     */
    private function stats(Shop $shop): array
    {
        $stats = (array) $shop->products()
            ->toBase()
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as active', [ProductStatus::Active->value])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as draft', [ProductStatus::Draft->value])
            ->selectRaw('sum(case when stock between 1 and ? then 1 else 0 end) as low_stock', [Product::LOW_STOCK_THRESHOLD])
            ->selectRaw('sum(case when stock = 0 then 1 else 0 end) as out_of_stock')
            ->selectRaw('sum(price * stock) as inventory_value')
            ->first();

        return [
            'total' => (int) ($stats['total'] ?? 0),
            'active' => (int) ($stats['active'] ?? 0),
            'draft' => (int) ($stats['draft'] ?? 0),
            'lowStock' => (int) ($stats['low_stock'] ?? 0),
            'outOfStock' => (int) ($stats['out_of_stock'] ?? 0),
            'inventoryValue' => round((float) ($stats['inventory_value'] ?? 0), 2),
        ];
    }

    /**
     * Get the pending shop invitations for the authenticated user.
     *
     * @return Collection<int, array{code: string, inviterName: string, shop: array{name: string, slug: string}}>
     */
    private function pendingInvitations(Request $request): Collection
    {
        $email = strtolower($request->user()->email);

        return ShopInvitation::query()
            ->with(['inviter', 'shop'])
            ->whereRaw('LOWER(email) = ?', [$email])
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>=', now()))
            ->latest()
            ->get()
            ->map(fn (ShopInvitation $invitation) => [
                'code' => $invitation->code,
                'inviterName' => $invitation->inviter->name,
                'shop' => [
                    'name' => $invitation->shop->name,
                    'slug' => $invitation->shop->slug,
                ],
            ]);
    }
}
