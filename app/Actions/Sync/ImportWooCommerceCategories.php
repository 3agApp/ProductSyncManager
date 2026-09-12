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

                $category = $this->save($shop, $remoteId, $name, $remoteCategory['slug'] ?? null);

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
     * Create or update one category, matching on its remote id.
     *
     * The store's own slug is preferred, since that is what makes the row
     * line up with the category as the store knows it. A slug already taken
     * by another of the shop's categories falls back to one derived from
     * the name, and then to the remote id, rather than failing the run.
     */
    private function save(Shop $shop, int $remoteId, string $name, mixed $remoteSlug): ?Category
    {
        $category = $shop->categories()->firstOrNew(['remote_id' => $remoteId]);

        foreach ($this->slugCandidates($remoteSlug, $name, $remoteId) as $slug) {
            $taken = $shop->categories()
                ->where('slug', $slug)
                ->when($category->exists, fn ($query) => $query->whereKeyNot($category->getKey()))
                ->exists();

            if ($taken) {
                continue;
            }

            $category->fill(['name' => $name, 'slug' => $slug])->save();

            return $category;
        }

        return null;
    }

    /**
     * Get the slugs to try for a category, in order of preference.
     *
     * @return array<int, string>
     */
    private function slugCandidates(mixed $remoteSlug, string $name, int $remoteId): array
    {
        $candidates = [
            Str::slug($this->text($remoteSlug ?? '')),
            Str::slug($name),
            Str::slug($name).'-'.$remoteId,
        ];

        return array_values(array_unique(array_filter($candidates)));
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
