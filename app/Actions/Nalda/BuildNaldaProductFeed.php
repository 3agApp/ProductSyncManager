<?php

namespace App\Actions\Nalda;

use App\Models\Category;
use App\Models\NaldaConnection;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class BuildNaldaProductFeed
{
    /**
     * The columns written, named as Nalda's product template names them.
     *
     * Nalda recognises columns by these names, so the order is only for
     * whoever opens the file.
     */
    public const array COLUMNS = [
        'gtin',
        'title',
        'country',
        'condition',
        'price',
        'tax',
        'currency',
        'delivery_time_days',
        'stock',
        'return_days',
        'main_image_url',
        'brand',
        'seller_category',
        'description',
        'image_2_url',
        'image_3_url',
        'image_4_url',
        'image_5_url',
        'delete_product',
    ];

    /**
     * Products read per query.
     */
    private const int CHUNK_SIZE = 500;

    /**
     * Write the shop's Nalda product file to the stream.
     *
     * Nalda never removes a product just because a file leaves it out, so
     * every product with a GTIN is written: listed products with their real
     * stock, and everything else with delete_product set. GTINs a previous
     * upload listed that no product carries any more get a delete row too.
     *
     * @param  resource  $stream
     */
    public function handle(NaldaConnection $connection, $stream): NaldaFeed
    {
        $shop = $connection->shop;
        [$chosen, $skipped] = $this->choose($connection);

        fputcsv($stream, self::COLUMNS, escape: '');

        $paths = Category::pathsFor($shop);
        $listedGtins = [];
        $removed = 0;

        $shop->products()
            ->whereIn('id', array_column($chosen, 'id'))
            ->with(['brand', 'categories'])
            ->chunkById(self::CHUNK_SIZE, function (Collection $products) use ($connection, $stream, $paths, &$listedGtins, &$removed) {
                foreach ($products as $product) {
                    /** @var Product $product */
                    $listed = $connection->lists($product);

                    fputcsv($stream, $this->productRow($connection, $product, $paths, $listed), escape: '');

                    if ($listed) {
                        $listedGtins[] = $this->gtin($product);
                    } else {
                        $removed++;
                    }
                }
            });

        $orphans = DB::table('nalda_listed_gtins')
            ->where('shop_id', $shop->id)
            ->whereNotIn('gtin', array_keys($chosen))
            ->orderBy('gtin')
            ->pluck('gtin');

        foreach ($orphans as $gtin) {
            fputcsv($stream, $this->removalRow($connection, (string) $gtin), escape: '');
            $removed++;
        }

        return new NaldaFeed(
            listed: count($listedGtins),
            removed: $removed,
            skipped: $skipped,
            listedGtins: $listedGtins,
        );
    }

    /**
     * Pick the one product written for each GTIN.
     *
     * Nalda keys products on the GTIN, so two products sharing one would
     * overwrite each other in whichever order the rows landed. A listed
     * product is preferred over an unlisted one, so a stale duplicate can
     * never take a live product off Nalda; between equals the oldest wins.
     *
     * @return array{0: array<string, array{id: int, listed: bool}>, 1: int}
     */
    private function choose(NaldaConnection $connection): array
    {
        $chosen = [];
        $skipped = 0;

        $connection->shop->products()
            ->select(['id', 'shop_id', 'gtin', 'status', 'nalda_listing'])
            ->chunkById(self::CHUNK_SIZE, function (Collection $products) use ($connection, &$chosen, &$skipped) {
                foreach ($products as $product) {
                    /** @var Product $product */
                    $gtin = $this->gtin($product);

                    if ($gtin === '') {
                        $skipped++;

                        continue;
                    }

                    $listed = $connection->lists($product);

                    if (! isset($chosen[$gtin])) {
                        $chosen[$gtin] = ['id' => $product->id, 'listed' => $listed];

                        continue;
                    }

                    $skipped++;

                    if ($listed && ! $chosen[$gtin]['listed']) {
                        $chosen[$gtin] = ['id' => $product->id, 'listed' => true];
                    }
                }
            });

        return [$chosen, $skipped];
    }

    /**
     * Build the row for a product.
     *
     * An unlisted product keeps its details but goes out with no stock as
     * well as the delete flag, since Nalda takes either as a removal.
     *
     * @param  array<int, string>  $paths
     * @return array<int, string|int>
     */
    private function productRow(NaldaConnection $connection, Product $product, array $paths, bool $listed): array
    {
        $gallery = array_slice($product->gallery_image_urls ?? [], 0, 4);

        $category = $product->categories
            ->map(fn (Category $category) => $paths[$category->id] ?? $category->name)
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->first();

        return [
            $this->gtin($product),
            $product->name,
            $connection->country,
            $connection->condition,
            $this->decimal($product->price),
            $this->decimal($connection->tax_rate),
            $connection->currency,
            $connection->delivery_time_days,
            $listed ? $product->stock : 0,
            $connection->return_days,
            $product->image_url ?? '',
            $product->brand->name ?? '',
            $category ?? '',
            $product->description ?: ($product->short_description ?? ''),
            $gallery[0] ?? '',
            $gallery[1] ?? '',
            $gallery[2] ?? '',
            $gallery[3] ?? '',
            $listed ? '' : 'yes',
        ];
    }

    /**
     * Build the row that takes a GTIN no product carries any more off Nalda.
     *
     * @return array<int, string|int>
     */
    private function removalRow(NaldaConnection $connection, string $gtin): array
    {
        $row = array_fill_keys(self::COLUMNS, '');

        return array_values([
            ...$row,
            'gtin' => $gtin,
            'country' => $connection->country,
            'condition' => $connection->condition,
            'tax' => $this->decimal($connection->tax_rate),
            'currency' => $connection->currency,
            'delivery_time_days' => $connection->delivery_time_days,
            'stock' => 0,
            'return_days' => $connection->return_days,
            'delete_product' => 'yes',
        ]);
    }

    /**
     * Get the product's GTIN as Nalda should see it, or an empty string.
     */
    private function gtin(Product $product): string
    {
        return trim((string) $product->gtin);
    }

    /**
     * Format a decimal without trailing zeros, so 8.10 is written as 8.1.
     */
    private function decimal(string|float $value): string
    {
        $formatted = number_format((float) $value, 2, '.', '');

        return str_contains($formatted, '.') ? rtrim(rtrim($formatted, '0'), '.') : $formatted;
    }
}
