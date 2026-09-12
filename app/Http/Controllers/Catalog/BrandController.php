<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\SaveBrandRequest;
use App\Models\Brand;
use App\Models\Shop;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class BrandController extends Controller
{
    /**
     * Show the shop's brands.
     */
    public function index(Request $request, Shop $currentShop): Response
    {
        return Inertia::render('brands/index', [
            'brands' => $currentShop->brands()
                ->withCount('products')
                ->orderBy('name')
                ->get()
                ->map(fn (Brand $brand) => [
                    'id' => $brand->id,
                    'name' => $brand->name,
                    'slug' => $brand->slug,
                    'productCount' => $brand->products_count,
                ])
                ->all(),
            'permissions' => $request->user()->toShopPermissions($currentShop),
        ]);
    }

    /**
     * Store a newly created brand.
     */
    public function store(SaveBrandRequest $request, Shop $currentShop): RedirectResponse
    {
        $name = (string) $request->validated('name');

        $currentShop->brands()->create(['name' => $name, 'slug' => Str::slug($name)]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Brand added.')]);

        return back();
    }

    /**
     * Update the specified brand.
     */
    public function update(SaveBrandRequest $request, Shop $currentShop, Brand $brand): RedirectResponse
    {
        $name = (string) $request->validated('name');

        $brand->update(['name' => $name, 'slug' => Str::slug($name)]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Brand updated.')]);

        return back();
    }

    /**
     * Delete the specified brand.
     *
     * The products keep their place in the catalog and simply lose the
     * brand, which the nullable column on the product takes care of.
     */
    public function destroy(Shop $currentShop, Brand $brand): RedirectResponse
    {
        Gate::authorize('delete', $brand);

        $brand->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Brand deleted.')]);

        return back();
    }
}
