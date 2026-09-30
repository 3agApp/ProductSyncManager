<?php

namespace App\Models;

use App\Enums\TemuProductStatus;
use Carbon\CarbonImmutable;
use Database\Factories\TemuProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What this app has sent to Temu for one product.
 *
 * @property int $id
 * @property int $shop_id
 * @property int|null $product_id
 * @property string $external_goods_id
 * @property string $external_sku_id
 * @property int|null $goods_id
 * @property int|null $sku_id
 * @property TemuProductStatus $status
 * @property int|null $temu_sub_status
 * @property string|null $message
 * @property string|null $sent_price
 * @property string|null $sent_list_price
 * @property int|null $sent_stock
 * @property string|null $content_hash
 * @property bool $on_sale
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $checked_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Shop $shop
 * @property-read Product|null $product
 */
#[Fillable([
    'product_id',
    'external_goods_id',
    'external_sku_id',
    'goods_id',
    'sku_id',
    'status',
    'temu_sub_status',
    'message',
    'sent_price',
    'sent_list_price',
    'sent_stock',
    'content_hash',
    'on_sale',
    'submitted_at',
    'checked_at',
])]
class TemuProduct extends Model
{
    /** @use HasFactory<TemuProductFactory> */
    use HasFactory;

    /**
     * Get the identifier Temu is given for a product.
     *
     * It is built from the local id rather than the SKU, which can be
     * edited, because Temu will not let an existing product's identifier
     * change.
     */
    public static function externalGoodsId(Product $product): string
    {
        return 'psm-'.$product->id;
    }

    /**
     * Get the shop that owns the record.
     *
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * Get the local product, unless it has since been deleted.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Determine if Temu has created the product.
     *
     * @phpstan-assert-if-true int $this->goods_id
     */
    public function existsOnTemu(): bool
    {
        return $this->goods_id !== null;
    }

    /**
     * Determine if the product can be sent to Temu again as it is.
     *
     * Only a product Temu never created can be retried; one it did create
     * is kept up to date by every sync anyway.
     */
    public function canRetry(): bool
    {
        return $this->status === TemuProductStatus::Failed && ! $this->existsOnTemu();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'goods_id' => 'integer',
            'sku_id' => 'integer',
            'status' => TemuProductStatus::class,
            'temu_sub_status' => 'integer',
            'sent_price' => 'decimal:2',
            'sent_list_price' => 'decimal:2',
            'sent_stock' => 'integer',
            'on_sale' => 'boolean',
            'submitted_at' => 'datetime',
            'checked_at' => 'datetime',
        ];
    }
}
