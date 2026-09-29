<?php

namespace App\Models;

use App\Enums\NaldaDefaultBehavior;
use App\Enums\NaldaPriceMode;
use App\Enums\ProductStatus;
use Carbon\CarbonImmutable;
use Database\Factories\NaldaConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $shop_id
 * @property string|null $sftp_username
 * @property string|null $sftp_password
 * @property string $file_name
 * @property NaldaDefaultBehavior $default_behavior
 * @property string $country
 * @property string $currency
 * @property string $condition
 * @property NaldaPriceMode $price_mode
 * @property int $delivery_time_days
 * @property int $return_days
 * @property bool $upload_after_sync
 * @property CarbonImmutable|null $verified_at
 * @property CarbonImmutable|null $last_exported_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Shop $shop
 */
#[Fillable([
    'sftp_username',
    'sftp_password',
    'file_name',
    'default_behavior',
    'country',
    'currency',
    'condition',
    'price_mode',
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

    /**
     * The name the product file is uploaded under when none is set.
     */
    public const string DEFAULT_FILE_NAME = 'products.csv';

    /**
     * The VAT rates Nalda accepts: Swiss standard and reduced.
     *
     * @var array<int, string>
     */
    public const array TAX_RATES = ['8.1', '2.6'];

    protected $hidden = ['sftp_password'];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'file_name' => self::DEFAULT_FILE_NAME,
    ];

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
     * Only active products with a price are ever listed: a draft is not
     * ready to sell, an archived product is gone from the store, and a
     * product without a price cannot be sold, whatever the product's own
     * choice says.
     */
    public function lists(Product $product): bool
    {
        return $product->status === ProductStatus::Active
            && (float) $product->price > 0
            && $product->nalda_listing->includes($this->default_behavior, $product->brand?->nalda_listing);
    }

    /**
     * Get the VAT rate to send, the accepted rate closest to the shop's own.
     *
     * A shop without a rate is taken to charge the standard rate.
     */
    public function taxRate(): string
    {
        $rate = (float) $this->shop->tax_rate;

        if ($rate <= 0) {
            return self::TAX_RATES[0];
        }

        return collect(self::TAX_RATES)
            ->sortBy(fn (string $accepted) => abs((float) $accepted - $rate))
            ->first();
    }

    /**
     * Determine if VAT is added to prices before they are sent.
     *
     * Nalda shows prices with VAT included, so this only ever applies to a
     * shop whose prices are entered without it.
     */
    public function addsTax(): bool
    {
        return ! $this->shop->prices_include_tax && $this->price_mode === NaldaPriceMode::AddTax;
    }

    /**
     * Get the price to send for the product, with two decimals.
     *
     * Added VAT is taken at the shop's own rate and rounded to five
     * centimes, as Swiss prices are.
     */
    public function price(Product $product): string
    {
        $price = (float) $product->price;

        if ($this->addsTax()) {
            $price = round($price * (1 + (float) $this->shop->tax_rate / 100) / 0.05) * 0.05;
        }

        return number_format($price, 2, '.', '');
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
            'price_mode' => NaldaPriceMode::class,
            'delivery_time_days' => 'integer',
            'return_days' => 'integer',
            'upload_after_sync' => 'boolean',
            'verified_at' => 'datetime',
            'last_exported_at' => 'datetime',
        ];
    }
}
