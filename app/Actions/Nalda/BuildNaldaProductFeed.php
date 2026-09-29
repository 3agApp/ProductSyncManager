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
     * The header row, exactly as Nalda's product template has it.
     *
     * Every column is written, in the template's order, even those the
     * catalog has no value for yet; those are left empty.
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
        'category',
        'google_category',
        'seller_category',
        'description',
        'length_mm',
        'width_mm',
        'height_mm',
        'weight_g',
        'shipping_length_mm',
        'shipping_width_mm',
        'shipping_height_mm',
        'shipping_weight_g',
        'volume_ml',
        'size',
        'colour',
        'image_2_url',
        'image_3_url',
        'image_4_url',
        'image_5_url',
        'delete_product',
        'author',
        'language',
        'format',
        'year',
        'publisher',
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

        $listedGtins = [];
        $removed = 0;

        $shop->products()
            ->whereIn('id', array_column($chosen, 'id'))
            ->with(['brand', 'categories'])
            ->chunkById(self::CHUNK_SIZE, function (Collection $products) use ($connection, $stream, &$listedGtins, &$removed) {
                foreach ($products as $product) {
                    /** @var Product $product */
                    $listed = $connection->lists($product);

                    fputcsv($stream, $this->productRow($connection, $product, $listed), escape: '');

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
            ->select(['id', 'shop_id', 'brand_id', 'gtin', 'price', 'status', 'nalda_listing'])
            ->with('brand:id,nalda_listing')
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
     * The seller category is the name of every category the product is
     * filed under, ordered by name and joined with " > ", the way the
     * WooCommerce Nalda plugin writes it.
     *
     * @return array<int, string|int>
     */
    private function productRow(NaldaConnection $connection, Product $product, bool $listed): array
    {
        $gallery = array_slice($product->gallery_image_urls ?? [], 0, 4);

        $category = $product->categories
            ->map(fn (Category $category) => $category->name)
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->implode(' > ');

        return $this->row([
            'gtin' => $this->gtin($product),
            'title' => $product->name,
            'country' => $connection->country,
            'condition' => $connection->condition,
            'price' => $connection->price($product),
            'tax' => $connection->taxRate(),
            'currency' => $connection->currency,
            'delivery_time_days' => $connection->delivery_time_days,
            'stock' => $listed ? $product->stock : 0,
            'return_days' => $connection->return_days,
            'main_image_url' => $product->image_url ?? '',
            'brand' => $product->brand->name ?? '',
            'seller_category' => $category,
            'description' => $product->description ?: ($product->short_description ?? ''),
            'image_2_url' => $gallery[0] ?? '',
            'image_3_url' => $gallery[1] ?? '',
            'image_4_url' => $gallery[2] ?? '',
            'image_5_url' => $gallery[3] ?? '',
            'delete_product' => $listed ? '' : 'yes',
        ]);
    }

    /**
     * Lay the values out under the header, leaving every other column empty.
     *
     * @param  array<string, string|int>  $values
     * @return array<int, string|int>
     */
    private function row(array $values): array
    {
        return array_values([...array_fill_keys(self::COLUMNS, ''), ...$values]);
    }

    /**
     * Build the row that takes a GTIN no product carries any more off Nalda.
     *
     * @return array<int, string|int>
     */
    private function removalRow(NaldaConnection $connection, string $gtin): array
    {
        return $this->row([
            'gtin' => $gtin,
            'country' => $connection->country,
            'condition' => $connection->condition,
            'tax' => $connection->taxRate(),
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
}
