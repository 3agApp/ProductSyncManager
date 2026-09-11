<?php

namespace App\Models;

use Database\Factories\WooCommerceConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $shop_id
 * @property string $store_url
 * @property string $consumer_key
 * @property string $consumer_secret
 * @property string|null $key_last_four
 * @property string|null $secret_last_four
 * @property Carbon|null $verified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Shop $shop
 */
#[Fillable([
    'store_url',
    'consumer_key',
    'consumer_secret',
    'key_last_four',
    'secret_last_four',
    'verified_at',
])]
#[Table('woocommerce_connections')]
class WooCommerceConnection extends Model
{
    /** @use HasFactory<WooCommerceConnectionFactory> */
    use HasFactory;

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
     * Determine if the stored credentials can still be decrypted.
     *
     * Credentials are encrypted with the application key, so rotating it
     * leaves them unreadable. Callers use this to prompt for re-entry
     * instead of failing with a decryption error.
     */
    public function hasUsableCredentials(): bool
    {
        return rescue(
            fn () => $this->consumer_key !== '' && $this->consumer_secret !== '',
            false,
            report: false,
        );
    }

    /**
     * Get the masked preview of the consumer key.
     */
    public function keyPreview(): string
    {
        return 'ck_'.str_repeat('•', 4).($this->key_last_four ?? '');
    }

    /**
     * Get the masked preview of the consumer secret.
     */
    public function secretPreview(): string
    {
        return 'cs_'.str_repeat('•', 4).($this->secret_last_four ?? '');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'consumer_key' => 'encrypted',
            'consumer_secret' => 'encrypted',
            'verified_at' => 'datetime',
        ];
    }
}
