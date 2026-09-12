<?php

namespace App\Actions\Catalog;

use App\Models\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteCategory
{
    /**
     * Describe the current impact. Products shared outside the removed branch
     * are never eligible for permanent deletion through this operation.
     *
     * @return array{categoryIds: array<int, int>, productIds: array<int, int>, exclusiveProductIds: array<int, int>, descendantCount: int, hasSyncedCategories: bool, token: string}
     */
    public function plan(Category $category, bool $deleteChildren, bool $lock = false): array
    {
        $categories = $category->shop->categories()->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get();
        $children = $categories->groupBy('parent_id');
        $branchIds = [$category->id];
        $queue = [$category->id];

        // A visited set handles old/deep trees without a depth limit or cycles.
        while ($queue !== []) {
            foreach ($children->get(array_shift($queue), []) as $child) {
                if (! in_array($child->id, $branchIds, true)) {
                    $branchIds[] = $child->id;
                    $queue[] = $child->id;
                }
            }
        }

        $ids = $deleteChildren ? $branchIds : [$category->id];
        sort($ids);

        $products = $category->shop->products()
            ->select('products.id')
            ->whereHas('categories', fn ($query) => $query->whereIn('categories.id', $ids))
            ->with('categories:id')
            ->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get();

        $productIds = $products->modelKeys();
        $exclusiveIds = $products->filter(fn ($product) => $product->categories->every(
            fn (Category $term) => in_array($term->id, $ids, true),
        ))->modelKeys();

        return [
            'categoryIds' => $ids,
            'productIds' => $productIds,
            'exclusiveProductIds' => $exclusiveIds,
            'descendantCount' => count($branchIds) - 1,
            'hasSyncedCategories' => $categories->whereIn('id', $ids)->whereNotNull('remote_id')->isNotEmpty(),
            'token' => hash('sha256', json_encode([
                $categories->map(fn (Category $term) => [$term->id, $term->parent_id, $term->name])->all(),
                $ids, $productIds, $exclusiveIds,
            ], JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * Apply a reviewed deletion as one transaction, including product moves.
     *
     * @return array{categories: int, products: int, moved: int}
     */
    public function handle(Category $category, string $children, string $products, ?int $destinationId, ?string $token): array
    {
        return DB::transaction(function () use ($category, $children, $products, $destinationId, $token) {
            $plan = $this->plan($category, $children === 'delete', lock: true);

            if ($token !== null && ! hash_equals($plan['token'], $token)) {
                throw ValidationException::withMessages(['preview_token' => __('The catalog changed. Refresh the preview and review the counts before deleting.')]);
            }

            $moved = 0;
            $deleted = 0;

            if ($products === 'move') {
                $destination = $category->shop->categories()->whereKey($destinationId)->first();

                if ($destination === null || in_array($destination->id, $plan['categoryIds'], true)) {
                    throw ValidationException::withMessages(['destination_id' => __('Choose a category that will remain after deletion.')]);
                }

                // insertOrIgnore preserves existing destination links and all
                // unrelated category assignments without duplicate pivots.
                foreach (array_chunk($plan['productIds'], 500) as $chunk) {
                    DB::table('category_product')->insertOrIgnore(array_map(fn (int $id) => [
                        'product_id' => $id,
                        'category_id' => $destination->id,
                    ], $chunk));
                }
                $moved = count($plan['productIds']);
            } elseif ($products === 'delete') {
                foreach (array_chunk($plan['exclusiveProductIds'], 500) as $chunk) {
                    $deleted += $category->shop->products()->whereKey($chunk)->delete();
                }
            }

            if ($children === 'keep') {
                $category->children()->update(['parent_id' => $category->parent_id]);
            }

            $deletedCategories = $category->shop->categories()->whereKey($plan['categoryIds'])->delete();

            return ['categories' => $deletedCategories, 'products' => $deleted, 'moved' => $moved];
        });
    }
}
