<?php

namespace App\Concerns;

use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;

trait PresentsProducts
{
    /**
     * Build the payload the product table and detail view read.
     *
     * @param  array<int, string>  $categoryPaths  written path, keyed by category id
     * @return array<string, mixed>
     */
    protected function productPayload(Product $product, array $categoryPaths): array
    {
        return [
            'id' => $product->id,
            'remoteId' => $product->remote_id,
            'name' => $product->name,
            'sku' => $product->sku,
            'gtin' => $product->gtin,
            'description' => $product->description,
            'shortDescription' => $product->short_description,
            'brand' => $product->brand?->name,
            'categories' => $product->categories
                ->map(fn (Category $category) => $categoryPaths[$category->id] ?? $category->name)
                ->values()
                ->all(),
            'imageUrl' => $product->image_url,
            'galleryImageUrls' => $product->gallery_image_urls ?? [],
            'permalink' => $product->permalink,
            'price' => $product->price,
            'stock' => $product->stock,
            'status' => $product->status->value,
            'statusLabel' => $product->status->label(),
            'naldaListing' => $product->nalda_listing->value,
            'temuListing' => $product->temu_listing->value,
            'updatedAt' => $product->updated_at?->toISOString(),
            'updatedAtDiff' => $product->updated_at?->diffForHumans(),
        ];
    }

    /**
     * Get the in-flight sync for the shop, if there is one.
     *
     * This is deliberately smaller than the payload the sync settings page
     * uses: the pages that show it only draw a progress banner, and reload
     * it on a timer while a sync runs.
     *
     * @return array{id: int, status: string, progress: int|null, processedProducts: int, totalProducts: int|null}|null
     */
    protected function activeSyncRun(Shop $shop): ?array
    {
        $run = $shop->syncRuns()->active()->latest('id')->first();

        if ($run === null) {
            return null;
        }

        return [
            'id' => $run->id,
            'status' => $run->status->value,
            'progress' => $run->progress(),
            'processedProducts' => $run->processed_products,
            'totalProducts' => $run->total_products,
        ];
    }
}
