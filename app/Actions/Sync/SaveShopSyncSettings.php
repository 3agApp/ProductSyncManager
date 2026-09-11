<?php

namespace App\Actions\Sync;

use App\Enums\SyncFrequency;
use App\Models\Shop;
use App\Models\ShopSyncSetting;

class SaveShopSyncSettings
{
    public function __construct(private readonly CalculateNextSyncRun $calculateNextSyncRun)
    {
        //
    }

    /**
     * Create or update a shop's sync schedule and recompute when it next runs.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(Shop $shop, array $data): ShopSyncSetting
    {
        $settings = $shop->syncSettings ?? new ShopSyncSetting;
        $settings->shop_id = $shop->id;

        $frequency = SyncFrequency::from((string) $data['frequency']);

        $settings->is_enabled = (bool) ($data['is_enabled'] ?? false);
        $settings->frequency = $frequency;
        $settings->time_of_day = (string) $data['time_of_day'];
        $settings->day_of_week = $frequency->requiresDayOfWeek() ? (int) $data['day_of_week'] : null;
        $settings->timezone = (string) $data['timezone'];

        $settings->next_run_at = $this->calculateNextSyncRun->handle($settings);

        $settings->save();

        return $settings->refresh();
    }
}
