<?php

namespace App\Actions\Sync;

use App\Enums\ShopType;
use App\Enums\SyncRunStatus;
use App\Enums\SyncTrigger;
use App\Jobs\SyncWooCommerceProducts;
use App\Models\Shop;
use App\Models\SyncRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class StartSyncRun
{
    /**
     * Queue a sync for the shop.
     *
     * Returns null when a run is already queued or in progress, which the
     * caller reports rather than treating as an error.
     */
    public function handle(Shop $shop, SyncTrigger $trigger, ?User $user = null): ?SyncRun
    {
        if (! $this->canSync($shop)) {
            return null;
        }

        $run = DB::transaction(function () use ($shop, $trigger, $user) {
            if ($shop->syncRuns()->active()->exists()) {
                return null;
            }

            return $shop->syncRuns()->create([
                'triggered_by' => $user?->id,
                'trigger' => $trigger,
                'status' => SyncRunStatus::Queued,
            ]);
        });

        if ($run === null) {
            return null;
        }

        // Dispatch after commit so a worker cannot pick the job up before the
        // run row it needs is visible.
        match ($shop->type) {
            ShopType::Wordpress => SyncWooCommerceProducts::dispatch($run)->afterCommit(),
        };

        return $run;
    }

    /**
     * Determine if the shop is configured well enough to sync.
     */
    public function canSync(Shop $shop): bool
    {
        if (! $shop->type->supportsSync()) {
            return false;
        }

        $connection = $shop->wooCommerceConnection;

        return $connection !== null && $connection->hasUsableCredentials();
    }
}
