<?php

namespace App\Http\Controllers\Products;

use App\Concerns\PresentsProducts;
use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Products\SaveProductRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    use PresentsProducts;

    /**
     * The number of products shown per page.
     */
    private const int PER_PAGE = 10;

    /**
     * Show the shop's products.
     */
    public function index(Request $request, Shop $currentShop): Response|RedirectResponse
    {
        $search = $request->string('search')->trim()->toString();
        $status = ProductStatus::tryFrom($request->string('status')->toString());

        $products = $currentShop->products()
            ->with(['brand', 'categories'])
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

        $categoryPaths = Category::pathsFor($currentShop);

        return Inertia::render('products/index', [
            'products' => $products->through(fn (Product $product) => $this->productPayload($product, $categoryPaths)),
            'filters' => [
                'search' => $search,
                'status' => $status?->value,
            ],
            'statuses' => ProductStatus::options(),
            'lowStockThreshold' => Product::LOW_STOCK_THRESHOLD,
            'permissions' => $request->user()->toShopPermissions($currentShop),
            'activeSyncRun' => fn () => $this->activeSyncRun($currentShop),
        ]);
    }

    /**
     * Show the form for creating a product.
     */
    public function create(Shop $currentShop): Response
    {
        Gate::authorize('create', [Product::class, $currentShop]);

        return Inertia::render('products/create', [
            'shop' => $this->shopProps($currentShop),
            'brands' => $this->brandNames($currentShop),
            'categories' => $this->categoryPaths($currentShop),
            'statuses' => ProductStatus::options(),
        ]);
    }

    /**
     * Show the form for editing a product.
     *
     * The shop is resolved first so the product binding is scoped to it.
     */
    public function edit(Shop $currentShop, Product $product): Response
    {
        Gate::authorize('update', $product);

        $product->loadMissing(['brand', 'categories']);

        $paths = Category::pathsFor($currentShop);
        $suggestions = $paths;
        sort($suggestions);

        return Inertia::render('products/edit', [
            'shop' => $this->shopProps($currentShop),
            'brands' => $this->brandNames($currentShop),
            'categories' => $suggestions,
            'product' => [
                'id' => $product->id,
                'remoteId' => $product->remote_id,
                'name' => $product->name,
                'sku' => $product->sku,
                'gtin' => $product->gtin,
                'description' => $product->description,
                'shortDescription' => $product->short_description,
                'brand' => $product->brand?->name,
                'categories' => $product->categories
                    ->map(fn (Category $category) => $paths[$category->id] ?? $category->name)
                    ->values()
                    ->all(),
                'imageUrl' => $product->image_url,
                'galleryImageUrls' => $product->gallery_image_urls ?? [],
                'permalink' => $product->permalink,
                'price' => $product->price,
                'stock' => $product->stock,
                'status' => $product->status->value,
            ],
            'statuses' => ProductStatus::options(),
        ]);
    }

    /**
     * Store a newly created product.
     */
    public function store(SaveProductRequest $request, Shop $currentShop): RedirectResponse
    {
        $product = $currentShop->products()->create($request->productAttributes());

        $this->syncCategories($request, $product);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product created.')]);

        return to_route('products.index', ['current_shop' => $currentShop->slug]);
    }

    /**
     * Update the specified product.
     *
     * The shop is resolved first so the product binding is scoped to it.
     */
    public function update(SaveProductRequest $request, Shop $currentShop, Product $product): RedirectResponse
    {
        $product->update($request->productAttributes());

        $this->syncCategories($request, $product);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product updated.')]);

        return to_route('products.index', ['current_shop' => $currentShop->slug]);
    }

    /**
     * Delete the specified product.
     *
     * The shop is resolved first so the product binding is scoped to it.
     */
    public function destroy(Shop $currentShop, Product $product): RedirectResponse
    {
        Gate::authorize('delete', $product);

        $product->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product deleted.')]);

        return back();
    }

    /**
     * File the product under the submitted categories.
     *
     * A request that carried no category field leaves the existing links
     * alone, which is what lets a caller post only the original fields
     * without emptying a synced product's categories.
     */
    private function syncCategories(SaveProductRequest $request, Product $product): void
    {
        $categoryIds = $request->categoryIds();

        if ($categoryIds !== null) {
            $product->categories()->sync($categoryIds);
        }
    }

    /**
     * Get the shop's brand names, for the form's suggestions.
     *
     * @return array<int, string>
     */
    private function brandNames(Shop $shop): array
    {
        return $shop->brands()->orderBy('name')->pluck('name')->all();
    }

    /**
     * Get the shop's category paths, for the form's suggestions.
     *
     * @return array<int, string>
     */
    private function categoryPaths(Shop $shop): array
    {
        $paths = array_values(Category::pathsFor($shop));

        sort($paths);

        return $paths;
    }

    /**
     * Build the shop props the product form needs.
     *
     * @return array<string, mixed>
     */
    private function shopProps(Shop $shop): array
    {
        return [
            'id' => $shop->id,
            'name' => $shop->name,
            'slug' => $shop->slug,
            'isPersonal' => $shop->is_personal,
        ];
    }
}
