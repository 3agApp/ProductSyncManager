<?php

namespace App\Actions\Sync;

use App\Models\Category;
use App\Models\Shop;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ImportWooCommerceCategories
{
    /**
     * Rebuild the shop's category tree from the store's categories.
     *
     * Rows are matched on the remote id, so a category that was renamed in
     * the store keeps its products rather than being replaced by a new row.
     * Parents are linked in a second pass, since a child can arrive before
     * the category it sits under.
     *
     * @param  array<int, array<string, mixed>>  $remoteCategories
     * @return array<int, int> local category id, keyed by remote id
     */
    public function handle(Shop $shop, array $remoteCategories): array
    {
        return DB::transaction(function () use ($shop, $remoteCategories) {
            $localIds = [];
            $parents = [];

            foreach ($remoteCategories as $remoteCategory) {
                $remoteId = (int) ($remoteCategory['id'] ?? 0);
                $name = $this->text($remoteCategory['name'] ?? '');

                if ($remoteId === 0 || Str::slug($name) === '') {
                    continue;
                }

                $category = Category::findOrCreateForRemote(
                    $shop,
                    $remoteId,
                    $name,
                    $this->text($remoteCategory['slug'] ?? null),
                );

                if ($category === null) {
                    continue;
                }

                $localIds[$remoteId] = $category->id;
                $parents[$remoteId] = (int) ($remoteCategory['parent'] ?? 0);
            }

            $this->linkParents($shop, $localIds, $parents);

            return $localIds;
        });
    }

    /**
     * Point each category at its parent.
     *
     * WooCommerce reports a top level category's parent as 0, and a parent
     * outside the imported set is treated the same way, so the category
     * sits at the top rather than pointing at nothing.
     *
     * @param  array<int, int>  $localIds
     * @param  array<int, int>  $parents
     */
    private function linkParents(Shop $shop, array $localIds, array $parents): void
    {
        foreach ($parents as $remoteId => $remoteParentId) {
            $parentId = $localIds[$remoteParentId] ?? null;

            // A category cannot be its own parent, whatever the store says.
            if ($parentId === $localIds[$remoteId]) {
                $parentId = null;
            }

            $shop->categories()
                ->whereKey($localIds[$remoteId])
                ->update(['parent_id' => $parentId]);
        }
    }

    /**
     * Turn a remote string into plain text, clamped to the column width.
     *
     * Stores hand back HTML, so tags go first and the entities they leave
     * behind are decoded after -- otherwise a category named "Schmuck &amp;
     * Accessoires" would be stored and shown with the entity still in it.
     */
    private function text(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        $text = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return Str::limit(trim($text), 255, '');
    }
}
