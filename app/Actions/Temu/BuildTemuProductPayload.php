<?php

namespace App\Actions\Temu;

use App\Models\Category;
use App\Models\Product;
use App\Models\TemuConnection;
use App\Models\TemuProduct;
use Illuminate\Support\Str;

/**
 * Turns a product into what Temu's product API takes.
 *
 * Temu's V3 add call works out the category, attributes, compliance details
 * and variant structure itself from the name, description, category name
 * and images, so only what the catalog actually knows is sent.
 */
class BuildTemuProductPayload
{
    /**
     * The most images Temu keeps for a product or a SKU.
     */
    private const int MAX_IMAGES = 10;

    private const int NAME_LENGTH = 500;

    private const int DESCRIPTION_LENGTH = 10000;

    private const int IDENTIFIER_LENGTH = 128;

    /**
     * The most stock Temu accepts for one SKU.
     */
    public const int MAX_STOCK = 999999;

    /**
     * Build the request for temu.local.goods.v3.add.
     *
     * @return array<string, mixed>
     */
    public function forCreate(TemuConnection $connection, Product $product, TemuProduct $record): array
    {
        $content = $this->content($product);

        $payload = [
            'goodsBasic' => array_filter([
                'externalGoodsId' => $record->external_goods_id,
                'goodsName' => $content['name'],
                'goodsDesc' => $content['description'],
                'extCatName' => $content['category'],
                'goodsCarouselImage' => $content['images'],
                'productType' => 1,
            ], fn (mixed $value) => $value !== '' && $value !== []),
            'skuList' => [array_filter([
                'externalSkuId' => $record->external_sku_id,
                'images' => $content['images'],
                'price' => array_filter([
                    'basePrice' => [
                        'amount' => $connection->price($product),
                        'currency' => $connection->currency,
                    ],
                    'listPrice' => $this->listPrice($connection, $product),
                ]),
                'quantity' => $this->stock($product),
                // Zero tells Temu to use its default package size and weight;
                // the catalog does not store either yet.
                'packageInfo' => ['weight' => '0', 'length' => '0', 'width' => '0', 'height' => '0'],
                'variations' => [],
                'barCode' => $this->barCode($product),
            ], fn (mixed $value) => $value !== null)],
        ];

        if ($content['brand'] !== '') {
            $payload['attributes'] = [['name' => 'Brand', 'value' => [$content['brand']]]];
        }

        return $payload;
    }

    /**
     * Get the product details that are sent as content, in a stable shape.
     *
     * Price and stock are left out: they change often and have calls of
     * their own.
     *
     * @return array{name: string, description: string, category: string, brand: string, images: array<int, string>}
     */
    public function content(Product $product): array
    {
        $product->loadMissing(['brand', 'categories']);

        $description = $product->description ?: ($product->short_description ?? '');

        return [
            'name' => Str::limit(trim($product->temu_name ?: $product->name), self::NAME_LENGTH, ''),
            'description' => Str::limit(trim($description), self::DESCRIPTION_LENGTH, ''),
            // A store that files products under their brand as well adds
            // nothing Temu can place a product by, so that category is left out.
            'category' => $product->categories
                ->map(fn (Category $category) => $category->name)
                ->reject(fn (string $name) => $product->brand !== null && Str::lower($name) === Str::lower($product->brand->name))
                ->sort(SORT_NATURAL | SORT_FLAG_CASE)
                ->implode(' / '),
            'brand' => trim((string) $product->brand?->name),
            'images' => $this->images($product),
        ];
    }

    /**
     * Get a fingerprint of the product's content, to tell when it has changed.
     */
    public function contentHash(Product $product): string
    {
        return hash('sha256', json_encode($this->content($product), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * Get the stock to send, within the range Temu accepts.
     */
    public function stock(Product $product): int
    {
        return max(0, min(self::MAX_STOCK, $product->stock));
    }

    /**
     * Get the SKU identifier Temu is given for a new product.
     *
     * The shop's own SKU is used, so the seller recognises it in Temu's
     * Seller Center, unless it is too long or has characters Temu refuses.
     */
    public function externalSkuId(Product $product): string
    {
        $sku = trim($product->sku);

        if ($sku === '' || strlen($sku) > self::IDENTIFIER_LENGTH || preg_match('/^[\x21-\x7E]+$/', $sku) !== 1) {
            return TemuProduct::externalGoodsId($product);
        }

        return $sku;
    }

    /**
     * Get the recommended retail price block, if the product has one.
     *
     * @return array{amount: string, currency: string}|null
     */
    public function listPrice(TemuConnection $connection, Product $product): ?array
    {
        $amount = $connection->listPrice($product);

        return $amount === null ? null : ['amount' => $amount, 'currency' => $connection->currency];
    }

    /**
     * Get the main image followed by the gallery, without repeats.
     *
     * @return array<int, string>
     */
    private function images(Product $product): array
    {
        $images = array_filter(
            [$product->image_url, ...($product->gallery_image_urls ?? [])],
            fn (mixed $url) => is_string($url) && str_starts_with($url, 'http'),
        );

        return array_slice(array_values(array_unique($images)), 0, self::MAX_IMAGES);
    }

    /**
     * Get the barcode block for the product's GTIN, if it has one.
     *
     * Temu corrects a wrong barcode type from the value itself, so the type
     * is only a best guess from the length.
     *
     * @return array{barCodeType: string, barCodeId: array<int, string>}|null
     */
    private function barCode(Product $product): ?array
    {
        $gtin = preg_replace('/\D/', '', (string) $product->gtin) ?? '';

        if ($gtin === '') {
            return null;
        }

        $type = match (strlen($gtin)) {
            12 => 'UPC',
            14 => 'GTIN-14',
            default => 'EAN',
        };

        return ['barCodeType' => $type, 'barCodeId' => [$gtin]];
    }
}
