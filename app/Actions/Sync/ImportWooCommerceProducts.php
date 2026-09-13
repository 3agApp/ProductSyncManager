<?php

namespace App\Actions\Sync;

use App\Enums\ProductStatus;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ImportWooCommerceProducts
{
    /**
     * The longest SKU the products table accepts.
     */
    private const int SKU_LENGTH = 64;

    /**
     * The longest GTIN the products table accepts.
     */
    private const int GTIN_LENGTH = 64;

    /**
     * The longest description the product form will accept back.
     */
    private const int DESCRIPTION_LENGTH = 65535;

    /**
     * Brand ids already resolved during this page, keyed by remote id.
     *
     * @var array<int, int>
     */
    private array $brandIds = [];

    /**
     * Local category ids from this run, keyed by remote id.
     *
     * @var array<int, int>
     */
    private array $categoryIds = [];

    /**
     * Import one page of remote products into the shop.
     *
     * The whole page runs in a single transaction, which keeps SQLite write
     * pressure down while a worker and the web server share the database.
     *
     * @param  array<int, array<string, mixed>>  $remoteProducts
     * @param  array<int, int>  $categoryIds  local category id, keyed by remote id
     */
    public function handle(
        Shop $shop,
        array $remoteProducts,
        CarbonInterface $runStartedAt,
        array $categoryIds = [],
    ): ImportResult {
        $this->brandIds = [];
        $this->categoryIds = $categoryIds;

        return DB::transaction(function () use ($shop, $remoteProducts, $runStartedAt) {
            $created = 0;
            $updated = 0;
            $skipped = 0;

            foreach ($remoteProducts as $remoteProduct) {
                $remoteId = (int) ($remoteProduct['id'] ?? 0);

                if ($remoteId === 0) {
                    $skipped++;

                    continue;
                }

                $existing = $this->match($shop, $remoteId, $this->sku($remoteProduct, $remoteId));

                $saved = $this->save(
                    $shop,
                    $existing,
                    $this->attributes($shop, $remoteProduct, $remoteId, $runStartedAt),
                    $remoteId,
                );

                if ($saved !== null) {
                    $saved->categories()->sync($this->categoriesFor($shop, $remoteProduct));
                }

                if ($saved === null) {
                    $skipped++;
                } elseif ($existing === null) {
                    $created++;
                } else {
                    $updated++;
                }
            }

            return new ImportResult($created, $updated, $skipped);
        });
    }

    /**
     * Keep only the remote products this app imports: published and simple.
     *
     * @param  array<int, array<string, mixed>>  $remoteProducts
     * @return array<int, array<string, mixed>>
     */
    public static function importable(array $remoteProducts): array
    {
        return array_values(array_filter(
            $remoteProducts,
            fn (array $product) => $product['status'] === 'publish' && $product['type'] === 'simple',
        ));
    }

    /**
     * Find the local product for a remote one.
     *
     * Matching prefers the stored remote id. Falling back to the SKU lets a
     * shop adopt products that were created by hand before syncing was set
     * up, but only when that product is not already bound to another remote
     * product.
     */
    private function match(Shop $shop, int $remoteId, string $sku): ?Product
    {
        $byRemoteId = $shop->products()->where('remote_id', $remoteId)->first();

        if ($byRemoteId !== null) {
            return $byRemoteId;
        }

        return $shop->products()
            ->where('sku', $sku)
            ->whereNull('remote_id')
            ->first();
    }

    /**
     * Persist the product, recovering from a SKU collision.
     *
     * A single unimportable product must never abort the rest of the page,
     * so a colliding SKU is retried once against the synthesized form and
     * then given up on.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function save(Shop $shop, ?Product $existing, array $attributes, int $remoteId): ?Product
    {
        foreach ([$attributes, [...$attributes, 'sku' => $this->fallbackSku($remoteId)]] as $candidate) {
            try {
                if ($existing !== null) {
                    $existing->update($candidate);

                    return $existing;
                }

                return $shop->products()->create($candidate);
            } catch (UniqueConstraintViolationException) {
                continue;
            }
        }

        return null;
    }

    /**
     * Map a remote product onto local product columns.
     *
     * @param  array<string, mixed>  $remoteProduct
     * @return array<string, mixed>
     */
    private function attributes(Shop $shop, array $remoteProduct, int $remoteId, CarbonInterface $runStartedAt): array
    {
        $images = $this->imageUrls($remoteProduct);

        return [
            'remote_id' => $remoteId,
            'name' => $this->text($remoteProduct['name'] ?? '', 255) ?: 'Untitled product',
            'sku' => $this->sku($remoteProduct, $remoteId),
            'gtin' => $this->gtin($remoteProduct),
            'description' => $this->nullableText($remoteProduct['description'] ?? '', self::DESCRIPTION_LENGTH),
            'short_description' => $this->nullableText($remoteProduct['short_description'] ?? '', self::DESCRIPTION_LENGTH),
            'brand_id' => $this->brandId($shop, $remoteProduct),
            'image_url' => $images[0] ?? null,
            'gallery_image_urls' => array_slice($images, 1) ?: null,
            'permalink' => $this->nullableText($remoteProduct['permalink'] ?? '', 2048),
            'price' => $this->price($remoteProduct),
            'stock' => $this->stock($remoteProduct),
            // Only published products are fetched, so anything that arrives
            // here is live in the store.
            'status' => ProductStatus::Active,
            'last_synced_at' => $runStartedAt,
        ];
    }

    /**
     * Get the SKU to store, synthesizing one when the store has none.
     *
     * @param  array<string, mixed>  $remoteProduct
     */
    private function sku(array $remoteProduct, int $remoteId): string
    {
        $sku = trim((string) ($remoteProduct['sku'] ?? ''));

        if ($sku === '') {
            return $this->fallbackSku($remoteId);
        }

        return Str::limit($sku, self::SKU_LENGTH, '');
    }

    /**
     * Get the synthesized SKU for a remote product.
     */
    private function fallbackSku(int $remoteId): string
    {
        return 'WC-'.$remoteId;
    }

    /**
     * Get the product's global identifier (GTIN, UPC, EAN or ISBN).
     *
     * WooCommerce exposes this as `global_unique_id` from version 9.2. Older
     * stores keep it in product meta, so that is checked as a fallback.
     *
     * @param  array<string, mixed>  $remoteProduct
     */
    private function gtin(array $remoteProduct): ?string
    {
        $gtin = $this->text($remoteProduct['global_unique_id'] ?? '', self::GTIN_LENGTH);

        if ($gtin !== '') {
            return $gtin;
        }

        $meta = $remoteProduct['meta_data'] ?? null;

        if (! is_array($meta)) {
            return null;
        }

        foreach ($meta as $entry) {
            if (! is_array($entry) || ! in_array($entry['key'] ?? '', ['_gtin', '_ean', '_upc'], true)) {
                continue;
            }

            $value = $this->text($entry['value'] ?? '', self::GTIN_LENGTH);

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Get the local categories a remote product is filed under.
     *
     * The tree was imported before the first page of products, so a remote
     * category is normally already known. One that is not -- added to the
     * store mid-run -- is created from what the product payload carries,
     * which has a name but no parent, so it lands at the top.
     *
     * @param  array<string, mixed>  $remoteProduct
     * @return array<int, int>
     */
    private function categoriesFor(Shop $shop, array $remoteProduct): array
    {
        $terms = $remoteProduct['categories'] ?? null;

        if (! is_array($terms)) {
            return [];
        }

        $ids = [];

        foreach ($terms as $term) {
            if (! is_array($term)) {
                continue;
            }

            $remoteId = (int) ($term['id'] ?? 0);

            if ($remoteId !== 0 && isset($this->categoryIds[$remoteId])) {
                $ids[] = $this->categoryIds[$remoteId];

                continue;
            }

            $category = Category::findOrCreatePath($shop, $this->text($term['name'] ?? '', 255));

            if ($category === null) {
                continue;
            }

            $ids[] = $category->id;

            if ($remoteId !== 0) {
                $this->categoryIds[$remoteId] = $category->id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Get the brand row a remote product belongs to.
     *
     * A local product carries one brand, so a store that puts several on a
     * product contributes only the first. The brand is matched on the id
     * the store knows it by, so renaming it there moves the existing row
     * rather than leaving an orphan behind and starting a second.
     *
     * @param  array<string, mixed>  $remoteProduct
     */
    private function brandId(Shop $shop, array $remoteProduct): ?int
    {
        $terms = $remoteProduct['brands'] ?? null;

        if (! is_array($terms)) {
            return null;
        }

        foreach ($terms as $term) {
            if (! is_array($term)) {
                continue;
            }

            $remoteId = (int) ($term['id'] ?? 0);
            $name = $this->text($term['name'] ?? '', 255);

            if ($name === '') {
                continue;
            }

            if ($remoteId !== 0 && isset($this->brandIds[$remoteId])) {
                return $this->brandIds[$remoteId];
            }

            // A store that names a brand without giving it an id still gets
            // one row per name, matched the way a typed one is.
            $brand = $remoteId === 0
                ? Brand::findOrCreateFor($shop, $name)
                : Brand::findOrCreateForRemote($shop, $remoteId, $name, $this->text($term['slug'] ?? '', 255));

            if ($brand === null) {
                continue;
            }

            if ($remoteId !== 0) {
                $this->brandIds[$remoteId] = $brand->id;
            }

            return $brand->id;
        }

        return null;
    }

    /**
     * Get the product's image addresses, primary first.
     *
     * Only the links are stored; the images themselves stay on the store.
     *
     * @param  array<string, mixed>  $remoteProduct
     * @return array<int, string>
     */
    private function imageUrls(array $remoteProduct): array
    {
        $images = $remoteProduct['images'] ?? null;

        if (! is_array($images)) {
            return [];
        }

        $urls = [];

        foreach ($images as $image) {
            $src = is_array($image) ? $this->text($image['src'] ?? '', 2048) : '';

            if ($src !== '' && filter_var($src, FILTER_VALIDATE_URL) !== false) {
                $urls[] = $src;
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * Get the price, falling back through WooCommerce's price fields.
     *
     * @param  array<string, mixed>  $remoteProduct
     */
    private function price(array $remoteProduct): string
    {
        foreach (['regular_price', 'price'] as $field) {
            $value = (string) ($remoteProduct[$field] ?? '');

            if (is_numeric($value)) {
                return number_format(max(0, (float) $value), 2, '.', '');
            }
        }

        return '0.00';
    }

    /**
     * Get the stock level, clamped to what the unsigned column accepts.
     *
     * @param  array<string, mixed>  $remoteProduct
     */
    private function stock(array $remoteProduct): int
    {
        $stock = $remoteProduct['stock_quantity'] ?? null;

        if (! is_numeric($stock)) {
            return 0;
        }

        return max(0, (int) $stock);
    }

    /**
     * Strip markup from a remote string, returning null when it is empty.
     */
    private function nullableText(mixed $value, int $limit): ?string
    {
        $text = $this->text($value, $limit);

        return $text === '' ? null : $text;
    }

    /**
     * Turn a remote string into plain text, clamped to the column width.
     *
     * Stores hand back HTML, so tags go first and the entities they leave
     * behind are decoded after -- otherwise a name like "Kitty&amp;fish"
     * would be stored and shown with the entity still in it. Decoding
     * before clamping keeps the limit honest, since it shortens the text.
     */
    private function text(mixed $value, int $limit): string
    {
        if (! is_string($value)) {
            return '';
        }

        $text = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return Str::limit(trim($text), $limit, '');
    }
}
