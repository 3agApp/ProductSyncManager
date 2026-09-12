<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\SaveCategoryRequest;
use App\Models\Category;
use App\Models\Shop;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    /**
     * Show the shop's category tree.
     *
     * Rows are ordered by their written path, which lists each category
     * directly under the one it sits in.
     */
    public function index(Request $request, Shop $currentShop): Response
    {
        $paths = Category::pathsFor($currentShop);

        $categories = $currentShop->categories()
            ->withCount(['products', 'children'])
            ->get()
            ->map(fn (Category $category) => [
                'id' => $category->id,
                'parentId' => $category->parent_id,
                'name' => $category->name,
                'slug' => $category->slug,
                'path' => $paths[$category->id] ?? $category->name,
                'depth' => substr_count($paths[$category->id] ?? '', Category::PATH_SEPARATOR),
                'remoteId' => $category->remote_id,
                'productCount' => $category->products_count,
                'childCount' => $category->children_count,
            ])
            ->sortBy('path', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        return Inertia::render('categories/index', [
            'categories' => $categories,
            'permissions' => $request->user()->toShopPermissions($currentShop),
        ]);
    }

    /**
     * Store a newly created category.
     */
    public function store(SaveCategoryRequest $request, Shop $currentShop): RedirectResponse
    {
        $name = (string) $request->validated('name');

        $currentShop->categories()->create([
            'name' => $name,
            'slug' => Str::slug($name),
            'parent_id' => $request->validated('parent_id'),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Category added.')]);

        return back();
    }

    /**
     * Update the specified category.
     */
    public function update(SaveCategoryRequest $request, Shop $currentShop, Category $category): RedirectResponse
    {
        $name = (string) $request->validated('name');

        $category->update([
            'name' => $name,
            'slug' => Str::slug($name),
            'parent_id' => $request->validated('parent_id'),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Category updated.')]);

        return back();
    }

    /**
     * Delete the specified category.
     *
     * Its products simply lose the link, and its subcategories move up to
     * where it sat rather than being deleted with it.
     */
    public function destroy(Shop $currentShop, Category $category): RedirectResponse
    {
        Gate::authorize('delete', $category);

        $category->children()->update(['parent_id' => $category->parent_id]);

        $category->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Category deleted.')]);

        return back();
    }
}
