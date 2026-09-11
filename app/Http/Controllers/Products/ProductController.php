<?php

namespace App\Http\Controllers\Products;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Products\SaveProductRequest;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    /**
     * Show the form for creating a product.
     */
    public function create(Shop $currentShop): Response
    {
        Gate::authorize('create', [Product::class, $currentShop]);

        return Inertia::render('products/create', [
            'shop' => $this->shopProps($currentShop),
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

        return Inertia::render('products/edit', [
            'shop' => $this->shopProps($currentShop),
            'product' => [
                'id' => $product->id,
                'remoteId' => $product->remote_id,
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
            ],
            'statuses' => ProductStatus::options(),
        ]);
    }

    /**
     * Store a newly created product.
     */
    public function store(SaveProductRequest $request, Shop $currentShop): RedirectResponse
    {
        $currentShop->products()->create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product created.')]);

        return to_route('dashboard', ['current_shop' => $currentShop->slug]);
    }

    /**
     * Update the specified product.
     *
     * The shop is resolved first so the product binding is scoped to it.
     */
    public function update(SaveProductRequest $request, Shop $currentShop, Product $product): RedirectResponse
    {
        $product->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product updated.')]);

        return to_route('dashboard', ['current_shop' => $currentShop->slug]);
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
