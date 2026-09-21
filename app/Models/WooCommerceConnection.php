<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\WooCommerceConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $shop_id
 * @property string $store_url
 * @property string $consumer_key
 * @property array<string, array{id: int, delivery_url: string}>|null $webhook_setup
 * @property CarbonImmutable|null $webhooks_configured_at
 * @property string|null $webhook_secret
 * @property string $consumer_secret
 * @property string|null $key_last_four
 * @property string|null $secret_last_four
 * @property CarbonImmutable|null $verified_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Shop $shop
 */
#[Fillable([
    'webhook_setup',
    'webhooks_configured_at',
    'webhook_secret',
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

    protected $hidden = ['webhook_secret'];

    public function webhookDeliveryUrl(): string
    {
        return rtrim((string) config('app.url'), '/').route('woocommerce.webhook', ['connection' => $this->id], absolute: false);
    }

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
     * Get the webhook secret, or null when none is stored or it cannot be decrypted.
     *
     * The secret is encrypted with the application key like the credentials,
     * so rotating that key leaves it unreadable too.
     */
    public function webhookSecret(): ?string
    {
        $secret = rescue(fn () => $this->webhook_secret, null, report: false);

        return filled($secret) ? $secret : null;
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
            'webhook_setup' => 'array',
            'webhooks_configured_at' => 'datetime',
            'webhook_secret' => 'encrypted',
            'consumer_key' => 'encrypted',
            'consumer_secret' => 'encrypted',
            'verified_at' => 'datetime',
        ];
    }
}
