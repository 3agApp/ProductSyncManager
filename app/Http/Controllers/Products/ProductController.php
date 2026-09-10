<?php

namespace App\Http\Controllers\Products;

use App\Http\Controllers\Controller;
use App\Http\Requests\Products\SaveProductRequest;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class ProductController extends Controller
{
    /**
     * Store a newly created product.
     */
    public function store(SaveProductRequest $request, Shop $currentShop): RedirectResponse
    {
        $currentShop->products()->create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product created.')]);

        return back();
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

        return back();
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
}
