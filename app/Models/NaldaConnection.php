<?php

namespace App\Models;

use App\Enums\NaldaDefaultBehavior;
use App\Enums\ProductStatus;
use Database\Factories\NaldaConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $shop_id
 * @property string|null $sftp_username
 * @property string|null $sftp_password
 * @property NaldaDefaultBehavior $default_behavior
 * @property string $country
 * @property string $currency
 * @property string $condition
 * @property string $tax_rate
 * @property int $delivery_time_days
 * @property int $return_days
 * @property bool $upload_after_sync
 * @property Carbon|null $verified_at
 * @property Carbon|null $last_exported_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Shop $shop
 */
#[Fillable([
    'sftp_username',
    'sftp_password',
    'default_behavior',
    'country',
    'currency',
    'condition',
    'tax_rate',
    'delivery_time_days',
    'return_days',
    'upload_after_sync',
    'verified_at',
    'last_exported_at',
])]
class NaldaConnection extends Model
{
    /** @use HasFactory<NaldaConnectionFactory> */
    use HasFactory;

    protected $hidden = ['sftp_password'];

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
     * Determine if the product should be listed on Nalda.
     *
     * Only active products are ever listed: a draft is not ready to sell and
     * an archived product is gone from the store, whatever the product's
     * own choice says.
     */
    public function lists(Product $product): bool
    {
        return $product->status === ProductStatus::Active
            && $product->nalda_listing->includes($this->default_behavior);
    }

    /**
     * Determine if SFTP credentials are stored and can still be decrypted.
     *
     * The password is encrypted with the application key, so rotating it
     * leaves the password unreadable. Callers use this to prompt for
     * re-entry instead of failing with a decryption error.
     */
    public function hasUsableCredentials(): bool
    {
        return rescue(
            fn () => filled($this->sftp_username) && filled($this->sftp_password),
            false,
            report: false,
        );
    }

    /**
     * Get the SFTP host every Nalda seller uploads to.
     */
    public function sftpHost(): string
    {
        return (string) config('services.nalda.sftp_host');
    }

    /**
     * Get the SFTP port every Nalda seller uploads to.
     */
    public function sftpPort(): int
    {
        return (int) config('services.nalda.sftp_port');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sftp_password' => 'encrypted',
            'default_behavior' => NaldaDefaultBehavior::class,
            'tax_rate' => 'decimal:2',
            'delivery_time_days' => 'integer',
            'return_days' => 'integer',
            'upload_after_sync' => 'boolean',
            'verified_at' => 'datetime',
            'last_exported_at' => 'datetime',
        ];
    }
}
