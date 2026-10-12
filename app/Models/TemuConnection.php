<?php

namespace App\Models;

use App\Enums\ProductStatus;
use App\Enums\TemuDefaultBehavior;
use Carbon\CarbonImmutable;
use Database\Factories\TemuConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $shop_id
 * @property string|null $app_key
 * @property string|null $app_secret
 * @property string|null $access_token
 * @property TemuDefaultBehavior $default_behavior
 * @property string $currency
 * @property string $discount_percent
 * @property bool $sync_after_sync
 * @property bool $import_orders
 * @property string $order_shipping
 * @property bool $confirm_shipments
 * @property int|null $carrier_id
 * @property int|null $mall_id
 * @property CarbonImmutable|null $token_expires_at
 * @property CarbonImmutable|null $verified_at
 * @property CarbonImmutable|null $last_synced_at
 * @property CarbonImmutable|null $orders_checked_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Shop $shop
 */
#[Fillable([
    'app_key',
    'app_secret',
    'access_token',
    'default_behavior',
    'currency',
    'discount_percent',
    'sync_after_sync',
    'import_orders',
    'order_shipping',
    'confirm_shipments',
    'carrier_id',
    'mall_id',
    'token_expires_at',
    'verified_at',
    'last_synced_at',
    'orders_checked_at',
])]
class TemuConnection extends Model
{
    /** @use HasFactory<TemuConnectionFactory> */
    use HasFactory;

    protected $hidden = ['app_secret', 'access_token'];

    /**
     * Get the shop that owns the connection.
     *
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * Determine if the product should be listed on Temu.
     *
     * Only active products with a price and an image are ever listed: Temu
     * refuses a product without an image, a draft is not ready to sell, and
     * an archived product is gone from the store.
     */
    public function lists(Product $product): bool
    {
        return $product->status === ProductStatus::Active
            && (float) $product->price > 0
            && filled($product->image_url)
            && $product->temu_listing->includes($this->default_behavior, $product->brand?->temu_listing);
    }

    /**
     * Get the base price to send for the product, with two decimals.
     *
     * Temu sells below the shop price by the brand's discount, or the
     * shop's when the brand has none, rounded to the nearest 5 Rappen like
     * any Swiss price. Temu shows Swiss prices with VAT included, as the
     * WooCommerce price already is.
     */
    public function price(Product $product): string
    {
        // Rounded to cents first so float noise cannot tip a price that
        // sits halfway between two 5 Rappen steps.
        $price = round((float) $product->price * (100 - $this->discount($product)) / 100, 2);

        // Rounding to 5 Rappen can never take a priced product down to 0.
        $rounded = max(0.05, round($price * 20) / 20);

        return number_format($rounded, 2, '.', '');
    }

    /**
     * Get the recommended retail price to send for the product, if any.
     *
     * The shop price is what the product sells for elsewhere, so it is the
     * retail price. Temu only takes one above the base price, so a product
     * sold at the shop price has none.
     */
    public function listPrice(Product $product): ?string
    {
        $listPrice = number_format((float) $product->price, 2, '.', '');

        return (float) $listPrice > (float) $this->price($product) ? $listPrice : null;
    }

    /**
     * Get the discount on the shop price for the product, in percent.
     */
    public function discount(Product $product): float
    {
        $brandDiscount = $product->brand?->temu_discount_percent;

        return (float) ($brandDiscount ?? $this->discount_percent);
    }

    /**
     * Determine if the key, secret and token are stored and can still be decrypted.
     *
     * The secret and token are encrypted with the application key, so
     * rotating it leaves them unreadable. Callers use this to prompt for
     * re-entry instead of failing with a decryption error.
     */
    public function hasUsableCredentials(): bool
    {
        return rescue(
            fn () => filled($this->app_key) && filled($this->app_secret) && filled($this->access_token),
            false,
            report: false,
        );
    }

    /**
     * Determine if the access token has passed its expiry date.
     */
    public function tokenHasExpired(): bool
    {
        return $this->token_expires_at !== null && $this->token_expires_at->isPast();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'app_secret' => 'encrypted',
            'access_token' => 'encrypted',
            'default_behavior' => TemuDefaultBehavior::class,
            'discount_percent' => 'decimal:2',
            'sync_after_sync' => 'boolean',
            'import_orders' => 'boolean',
            'order_shipping' => 'decimal:2',
            'confirm_shipments' => 'boolean',
            'carrier_id' => 'integer',
            'mall_id' => 'integer',
            'token_expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'orders_checked_at' => 'datetime',
        ];
    }
}
