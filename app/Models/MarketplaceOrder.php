<?php

namespace App\Models;

use App\Enums\Marketplace;
use App\Enums\MarketplaceOrderStatus;
use Carbon\CarbonImmutable;
use Database\Factories\MarketplaceOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A marketplace order and the WooCommerce order it was imported as.
 *
 * The delivery fields are read back from the WooCommerce order, where the
 * Product Sync Manager plugin keeps them, and are what this app reports to
 * the marketplace.
 *
 * @property int $id
 * @property int $shop_id
 * @property Marketplace $marketplace
 * @property string $external_order_id
 * @property string $marketplace_status
 * @property MarketplaceOrderStatus $status
 * @property int|null $woocommerce_order_id
 * @property string|null $message
 * @property string|null $customer_total
 * @property string|null $commission
 * @property string|null $payout_status
 * @property string|null $delivery_status
 * @property string|null $tracking_code
 * @property CarbonImmutable|null $shipment_confirmed_at
 * @property string|null $shipment_message
 * @property CarbonImmutable|null $expected_delivery_on
 * @property array<int, string>|null $gtins
 * @property CarbonImmutable|null $ordered_at
 * @property CarbonImmutable|null $ship_by_at
 * @property CarbonImmutable|null $imported_at
 * @property CarbonImmutable|null $woocommerce_modified_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Shop $shop
 */
#[Fillable([
    'marketplace',
    'external_order_id',
    'marketplace_status',
    'status',
    'woocommerce_order_id',
    'message',
    'customer_total',
    'commission',
    'payout_status',
    'delivery_status',
    'tracking_code',
    'shipment_confirmed_at',
    'shipment_message',
    'expected_delivery_on',
    'gtins',
    'ordered_at',
    'ship_by_at',
    'imported_at',
    'woocommerce_modified_at',
])]
class MarketplaceOrder extends Model
{
    /** @use HasFactory<MarketplaceOrderFactory> */
    use HasFactory;

    /** Temu's parent order status: placed, still open to cancellation or an address change. */
    public const int TEMU_PENDING = 1;

    /** Temu's parent order status: paid and waiting to be shipped. */
    public const int TEMU_UNSHIPPED = 2;

    /** Temu's parent order status: cancelled. */
    public const int TEMU_CANCELLED = 3;

    /** Temu's parent order status: shipped. */
    public const int TEMU_SHIPPED = 4;

    /** Hours before Temu's ship-by deadline an unshipped order is flagged. */
    public const int SHIP_BY_WARNING_HOURS = 24;

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
     * Limit a query to one marketplace's orders.
     *
     * @param  Builder<MarketplaceOrder>  $query
     */
    public function scopeForMarketplace(Builder $query, Marketplace $marketplace): void
    {
        $query->where('marketplace', $marketplace->value);
    }

    /**
     * How close an order Temu still waits on is to its ship-by deadline.
     *
     * @return 'overdue'|'soon'|null
     */
    public function shipByWarning(): ?string
    {
        $waiting = $this->marketplace === Marketplace::Temu
            && $this->shipment_confirmed_at === null
            && $this->status !== MarketplaceOrderStatus::Cancelled
            && in_array((int) $this->marketplace_status, [self::TEMU_PENDING, self::TEMU_UNSHIPPED], true);

        if (! $waiting || $this->ship_by_at === null) {
            return null;
        }

        return match (true) {
            $this->ship_by_at->isPast() => 'overdue',
            $this->ship_by_at->lte(now()->addHours(self::SHIP_BY_WARNING_HOURS)) => 'soon',
            default => null,
        };
    }

    /**
     * Describe the order's status on the marketplace in words.
     */
    public function describeMarketplaceStatus(): string
    {
        return match ($this->marketplace) {
            Marketplace::Temu => self::describeTemuStatus((int) $this->marketplace_status),
            Marketplace::Nalda => ucfirst(strtolower(str_replace('_', ' ', $this->marketplace_status))),
        };
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
            'marketplace' => Marketplace::class,
            'status' => MarketplaceOrderStatus::class,
            'woocommerce_order_id' => 'integer',
            'customer_total' => 'decimal:2',
            'commission' => 'decimal:2',
            'expected_delivery_on' => 'date',
            'gtins' => 'array',
            'ordered_at' => 'datetime',
            'ship_by_at' => 'datetime',
            'imported_at' => 'datetime',
            'woocommerce_modified_at' => 'datetime',
            'shipment_confirmed_at' => 'datetime',
        ];
    }
}
