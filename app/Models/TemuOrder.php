<?php

namespace App\Models;

use App\Enums\TemuOrderStatus;
use Carbon\CarbonImmutable;
use Database\Factories\TemuOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Temu order and the WooCommerce order it was imported as.
 *
 * @property int $id
 * @property int $shop_id
 * @property string $parent_order_sn
 * @property int $temu_status
 * @property TemuOrderStatus $status
 * @property int|null $woocommerce_order_id
 * @property string|null $message
 * @property CarbonImmutable|null $ordered_at
 * @property CarbonImmutable|null $ship_by_at
 * @property CarbonImmutable|null $imported_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Shop $shop
 */
#[Fillable([
    'parent_order_sn',
    'temu_status',
    'status',
    'woocommerce_order_id',
    'message',
    'ordered_at',
    'ship_by_at',
    'imported_at',
])]
class TemuOrder extends Model
{
    /** @use HasFactory<TemuOrderFactory> */
    use HasFactory;

    /** Temu's parent order status: placed, still open to cancellation or an address change. */
    public const int TEMU_PENDING = 1;

    /** Temu's parent order status: paid and waiting to be shipped. */
    public const int TEMU_UNSHIPPED = 2;

    /** Temu's parent order status: cancelled. */
    public const int TEMU_CANCELLED = 3;

    /**
     * Get the shop that owns the order.
     *
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * Describe Temu's parent order status in words.
     */
    public static function describeTemuStatus(int $code): string
    {
        return match ($code) {
            1 => 'Pending',
            2 => 'To ship',
            3 => 'Cancelled',
            4 => 'Shipped',
            5 => 'Delivered',
            41 => 'Partly shipped',
            51 => 'Partly delivered',
            default => "Temu status {$code}",
        };
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'temu_status' => 'integer',
            'status' => TemuOrderStatus::class,
            'woocommerce_order_id' => 'integer',
            'ordered_at' => 'datetime',
            'ship_by_at' => 'datetime',
            'imported_at' => 'datetime',
        ];
    }
}
