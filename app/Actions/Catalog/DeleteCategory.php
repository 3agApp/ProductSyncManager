<?php

namespace App\Actions\Catalog;

use App\Enums\CategoryChildrenAction;
use App\Enums\CategoryProductsAction;
use App\Models\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteCategory
{
    /**
     * Rows written per statement, which keeps each one under database
     * parameter limits.
     */
    private const int CHUNK_SIZE = 500;

    /**
     * Determine if a choice removes more than the category itself, so the
     * user must have reviewed a preview of what it affects.
     */
    public static function needsReview(CategoryChildrenAction $children, CategoryProductsAction $products): bool
    {
        return $children === CategoryChildrenAction::Delete || $products !== CategoryProductsAction::Keep;
    }

    /**
     * Describe what deleting the category would affect.
     *
     * Products shared outside the removed categories are never eligible for
     * permanent deletion. The token fingerprints exactly what the preview
     * counted -- the removed categories and the affected, exclusive products --
     * so a deletion refuses to run once those change, while edits elsewhere in
     * the tree leave a reviewed preview valid.
     *
     * @return array{categoryIds: array<int, int>, productIds: array<int, int>, exclusiveProductIds: array<int, int>, descendantCount: int, hasSyncedCategories: bool, token: string}
     */
    public function plan(Category $category, CategoryChildrenAction $children, bool $lock = false): array
    {
        $categories = $category->shop->categories()->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get();
        $childrenByParent = $categories->groupBy('parent_id');
        $branch = [$category->id => true];
        $queue = [$category->id];

        // Walk the whole branch with no depth limit. The visited set keeps a
        // corrupted tree with a cycle from looping forever.
        while ($queue !== []) {
            foreach ($childrenByParent->get(array_shift($queue), []) as $child) {
                if (! isset($branch[$child->id])) {
                    $branch[$child->id] = true;
                    $queue[] = $child->id;
                }
            }
        }

        $ids = $children === CategoryChildrenAction::Delete ? array_keys($branch) : [$category->id];
        sort($ids);
        $removed = array_flip($ids);

        $products = $category->shop->products()
            ->select('products.id')
            ->whereHas('categories', fn ($query) => $query->whereIn('categories.id', $ids))
            ->with('categories:id')
            ->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get();

        $productIds = $products->modelKeys();
        $exclusiveIds = $products->filter(fn ($product) => $product->categories->every(
            fn (Category $term) => isset($removed[$term->id]),
        ))->modelKeys();

        return [
            'categoryIds' => $ids,
            'productIds' => $productIds,
            'exclusiveProductIds' => $exclusiveIds,
            'descendantCount' => count($branch) - 1,
            'hasSyncedCategories' => $categories->whereIn('id', $ids)->whereNotNull('remote_id')->isNotEmpty(),
            'token' => hash('sha256', json_encode([$ids, $productIds, $exclusiveIds], JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * Apply a reviewed deletion as one transaction, including product moves.
     *
     * @return array{categories: int, products: int, moved: int}
     */
    public function handle(
        Category $category,
        CategoryChildrenAction $children,
        CategoryProductsAction $products,
        ?int $destinationId,
        ?string $token,
    ): array {
        return DB::transaction(function () use ($category, $children, $products, $destinationId, $token) {
            $plan = $this->plan($category, $children, lock: true);

            // Checked here rather than trusting every caller to require a
            // preview: a given token must match, and a destructive choice
            // must come with one.
            $reviewed = $token !== null && hash_equals($plan['token'], $token);

            if (! $reviewed && ($token !== null || self::needsReview($children, $products))) {
                throw ValidationException::withMessages(['preview_token' => __('The catalog changed. Refresh the preview and review the counts before deleting.')]);
            }

            $moved = 0;
            $deleted = 0;

            if ($products === CategoryProductsAction::Move) {
                $destination = $category->shop->categories()->whereKey($destinationId)->first();

                if ($destination === null || in_array($destination->id, $plan['categoryIds'], true)) {
                    throw ValidationException::withMessages(['destination_id' => __('Choose a category that will remain after deletion.')]);
                }

                // insertOrIgnore preserves existing destination links and all
                // unrelated category assignments without duplicate pivots.
                foreach (array_chunk($plan['productIds'], self::CHUNK_SIZE) as $chunk) {
                    DB::table('category_product')->insertOrIgnore(array_map(fn (int $id) => [
                        'product_id' => $id,
                        'category_id' => $destination->id,
                    ], $chunk));
                }
                $moved = count($plan['productIds']);
            } elseif ($products === CategoryProductsAction::Delete) {
                foreach (array_chunk($plan['exclusiveProductIds'], self::CHUNK_SIZE) as $chunk) {
                    $deleted += $category->shop->products()->whereKey($chunk)->delete();
                }
            }

            if ($children === CategoryChildrenAction::Keep) {
                $category->children()->update(['parent_id' => $category->parent_id]);
            }

            $deletedCategories = $category->shop->categories()->whereKey($plan['categoryIds'])->delete();

            return ['categories' => $deletedCategories, 'products' => $deleted, 'moved' => $moved];
        });
    }
}
