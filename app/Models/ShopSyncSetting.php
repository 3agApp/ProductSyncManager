<?php

namespace App\Models;

use App\Enums\SyncFrequency;
use Database\Factories\ShopSyncSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $shop_id
 * @property bool $is_enabled
 * @property SyncFrequency $frequency
 * @property string $time_of_day
 * @property int|null $day_of_week
 * @property string $timezone
 * @property Carbon|null $last_run_at
 * @property Carbon|null $next_run_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Shop $shop
 */
#[Fillable([
    'is_enabled',
    'frequency',
    'time_of_day',
    'day_of_week',
    'timezone',
    'last_run_at',
    'next_run_at',
])]
class ShopSyncSetting extends Model
{
    /** @use HasFactory<ShopSyncSettingFactory> */
    use HasFactory;

    /**
     * Get the shop that owns the settings.
     *
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'frequency' => SyncFrequency::class,
            'day_of_week' => 'integer',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
        ];
    }
}
