<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\DeleteCategory;
use App\Enums\CategoryChildrenAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\DeleteCategoryRequest;
use App\Http\Requests\Catalog\SaveCategoryRequest;
use App\Models\Category;
use App\Models\Shop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
     * Preview unique affected products and surviving destinations.
     */
    public function deletionPreview(Request $request, Shop $currentShop, Category $category, DeleteCategory $delete): JsonResponse
    {
        Gate::authorize('delete', $category);
        $validated = $request->validate(['children' => ['sometimes', 'required', Rule::enum(CategoryChildrenAction::class)]]);
        $children = CategoryChildrenAction::tryFrom($validated['children'] ?? '') ?? CategoryChildrenAction::Keep;
        $plan = $delete->plan($category, $children);
        $paths = Category::pathsFor($currentShop);

        return response()->json([
            'categoryCount' => count($plan['categoryIds']),
            'descendantCount' => $plan['descendantCount'],
            'productCount' => count($plan['productIds']),
            'exclusiveProductCount' => count($plan['exclusiveProductIds']),
            'sharedProductCount' => count($plan['productIds']) - count($plan['exclusiveProductIds']),
            'hasSyncedCategories' => $plan['hasSyncedCategories'],
            'token' => $plan['token'],
            'destinations' => $currentShop->categories()->whereNotIn('id', $plan['categoryIds'])->get()
                ->map(fn (Category $term) => ['id' => $term->id, 'path' => $paths[$term->id] ?? $term->name])
                ->sortBy('path', SORT_NATURAL | SORT_FLAG_CASE)->values()->all(),
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * Delete the category, doing what the user chose with its children and products.
     */
    public function destroy(DeleteCategoryRequest $request, Shop $currentShop, Category $category, DeleteCategory $delete): RedirectResponse
    {
        $products = $request->productsAction();

        $result = $delete->handle(
            $category,
            $request->childrenAction(),
            $products,
            $request->filled('destination_id') ? (int) $request->validated('destination_id') : null,
            $request->validated('preview_token'),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $products->message($result['categories'], $result['products'], $result['moved']),
        ]);

        return back();
    }
}
