<?php

namespace App\Http\Controllers\Products;

use App\Concerns\PresentsProducts;
use App\Enums\NaldaListing;
use App\Enums\ProductStatus;
use App\Enums\TemuListing;
use App\Http\Controllers\Controller;
use App\Http\Requests\Products\SaveProductRequest;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    use PresentsProducts;

    /**
     * The page sizes a product list may be shown at.
     */
    private const array PAGE_SIZES = [10, 25, 50, 100];

    /**
     * The value standing for "filed under nothing" or "no brand".
     */
    private const string NONE = 'none';

    /**
     * Show the shop's products.
     */
    public function index(Request $request, Shop $currentShop): Response|RedirectResponse
    {
        $search = $request->string('search')->trim()->toString();
        $status = ProductStatus::tryFrom($request->string('status')->toString());
        $sort = $request->string('sort')->toString();
        $sort = array_key_exists($sort, Product::SORTABLE) ? $sort : Product::DEFAULT_SORT;
        $direction = $request->string('direction')->toString();
        $direction = in_array($direction, ['asc', 'desc'], true) ? $direction : Product::defaultDirection($sort);
        $perPage = $this->perPage($request);

        $categoryFilter = $request->string('category')->toString();
        $brandFilter = $request->string('brand')->toString();

        // Resolving through the shop is what stops another shop's category
        // or brand being used as a filter here; an id that does not belong
        // simply drops out rather than hiding the whole catalog.
        $category = $this->find($currentShop->categories(), $categoryFilter);
        $brand = $this->find($currentShop->brands(), $brandFilter);

        $query = $currentShop->products()
            ->with(['brand', 'categories'])
            ->matching($search);

        if ($status !== null) {
            $query->where('status', $status);
        }

        if ($category instanceof Category) {
            $query->inCategories($category->subtreeIds());
        } elseif ($categoryFilter === self::NONE) {
            $query->uncategorized();
        }

        if ($brand instanceof Brand) {
            $query->where('brand_id', $brand->id);
        } elseif ($brandFilter === self::NONE) {
            $query->whereNull('brand_id');
        }

        $products = $query->sorted($sort, $direction)->paginate($perPage)->withQueryString();

        if ($products->isEmpty() && $products->currentPage() > 1) {
            return redirect($products->url($products->lastPage()));
        }

        $categoryPaths = Category::pathsFor($currentShop);

        return Inertia::render('products/index', [
            'products' => $products->through(fn (Product $product) => $this->productPayload($product, $categoryPaths)),
            'filters' => [
                'search' => $search,
                'status' => $status?->value,
                'category' => $this->filterValue($category, $categoryFilter),
                'brand' => $this->filterValue($brand, $brandFilter),
                'sort' => $sort,
                'direction' => $direction,
                'perPage' => $perPage,
            ],
            'statuses' => ProductStatus::options(),
            'categoryOptions' => $this->categoryOptions($currentShop, $categoryPaths),
            'brandOptions' => $this->brandOptions($currentShop),
            'pageSizes' => self::PAGE_SIZES,
            'lowStockThreshold' => Product::LOW_STOCK_THRESHOLD,
            'permissions' => $request->user()->toShopPermissions($currentShop),
            'activeSyncRun' => fn () => $this->activeSyncRun($currentShop),
        ]);
    }

    /**
     * Find the filtered row within the shop, if the value names one.
     *
     * @param  HasMany<Brand, Shop>|HasMany<Category, Shop>  $relation
     */
    private function find(HasMany $relation, string $value): Brand|Category|null
    {
        if ($value === '' || $value === self::NONE) {
            return null;
        }

        $model = $relation->find($value);

        return $model instanceof Brand || $model instanceof Category ? $model : null;
    }

    /**
     * Get the filter value to echo back to the page.
     *
     * A filter that resolved to nothing is reported as absent, so the page
     * shows the catalog rather than an option that is not really applied.
     */
    private function filterValue(Brand|Category|null $model, string $submitted): ?string
    {
        if ($model !== null) {
            return (string) $model->id;
        }

        return $submitted === self::NONE ? self::NONE : null;
    }

    /**
     * Get the page size, falling back to the smallest offered.
     */
    private function perPage(Request $request): int
    {
        $perPage = $request->integer('per_page');

        return in_array($perPage, self::PAGE_SIZES, true) ? $perPage : self::PAGE_SIZES[0];
    }

    /**
     * Get the shop's categories as filter options, ordered by path.
     *
     * @param  array<int, string>  $paths
     * @return array<int, array{value: string, label: string}>
     */
    private function categoryOptions(Shop $shop, array $paths): array
    {
        $options = $shop->categories()
            ->get()
            ->map(fn (Category $category) => [
                'value' => (string) $category->id,
                'label' => $paths[$category->id] ?? $category->name,
            ])
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        return $options;
    }

    /**
     * Get the shop's brands as filter options.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function brandOptions(Shop $shop): array
    {
        return $shop->brands()
            ->orderBy('name')
            ->get()
            ->map(fn (Brand $brand) => ['value' => (string) $brand->id, 'label' => $brand->name])
            ->all();
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
            'naldaListings' => NaldaListing::options(),
            'temuListings' => TemuListing::options(),
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
                'naldaListing' => $product->nalda_listing->value,
                'temuListing' => $product->temu_listing->value,
            ],
            'statuses' => ProductStatus::options(),
            'naldaListings' => NaldaListing::options(),
            'temuListings' => TemuListing::options(),
        ]);
    }

    /**
     * Store a newly created product.
     */
    public function store(SaveProductRequest $request, Shop $currentShop): RedirectResponse
    {
        // Resolving the brand and category paths can create rows of their
        // own, so the whole save commits together or not at all.
        DB::transaction(function () use ($request, $currentShop) {
            $product = $currentShop->products()->create($request->productAttributes());

            $this->syncCategories($request, $product);
        });

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
        // Resolving the brand and category paths can create rows of their
        // own, so the whole save commits together or not at all.
        DB::transaction(function () use ($request, $product) {
            $product->update($request->productAttributes());

            $this->syncCategories($request, $product);
        });

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

        if ($categoryIds === null) {
            return;
        }

        $changes = $product->categories()->sync($categoryIds);

        // The categories live on a pivot, so the product itself has to be
        // touched for a marketplace to see it changed.
        if (array_filter($changes) !== []) {
            $product->touch();
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
            'currency' => $shop->currency,
        ];
    }
}
