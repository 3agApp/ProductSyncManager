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
 * @property bool $sync_after_sync
 * @property int|null $mall_id
 * @property CarbonImmutable|null $token_expires_at
 * @property CarbonImmutable|null $verified_at
 * @property CarbonImmutable|null $last_synced_at
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
    'sync_after_sync',
    'mall_id',
    'token_expires_at',
    'verified_at',
    'last_synced_at',
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
     * Get the price to send for the product, with two decimals.
     *
     * Temu shows Swiss prices with VAT included, and the WooCommerce price
     * is sent exactly as the shop has it.
     */
    public function price(Product $product): string
    {
        return number_format((float) $product->price, 2, '.', '');
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
            'sync_after_sync' => 'boolean',
            'mall_id' => 'integer',
            'token_expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }
}
