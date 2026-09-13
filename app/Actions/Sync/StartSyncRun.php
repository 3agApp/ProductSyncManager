<?php

namespace App\Actions\Sync;

use App\Enums\ShopType;
use App\Enums\SyncRunStatus;
use App\Enums\SyncTrigger;
use App\Jobs\SyncWooCommerceProducts;
use App\Models\Shop;
use App\Models\SyncRun;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Throwable;

class StartSyncRun
{
    /**
     * Queue a sync for the shop.
     *
     * Returns null when a run is already queued or in progress, which the
     * caller reports rather than treating as an error. Returns a failed run
     * when the job could not be queued.
     */
    public function handle(Shop $shop, SyncTrigger $trigger, ?User $user = null): ?SyncRun
    {
        if (! $this->canSync($shop)) {
            return null;
        }

        // A check for an active run followed by an insert lets two concurrent
        // requests both pass. The unique index on active_shop_id refuses the
        // second insert instead, on every database driver.
        try {
            $run = DB::transaction(fn () => $shop->syncRuns()->create([
                'triggered_by' => $user?->id,
                'trigger' => $trigger,
                'status' => SyncRunStatus::Queued,
            ]));
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        // Dispatch after commit so a worker cannot pick the job up before the
        // run row it needs is visible.
        $job = match ($shop->type) {
            ShopType::Wordpress => (new SyncWooCommerceProducts($run))->afterCommit(),
        };

        try {
            Bus::dispatch($job);
        } catch (Throwable $exception) {
            // Without a job nothing would ever finish the run, and it would
            // hold the shop's active slot until the stalled-run reaper came by.
            report($exception);

            $run->markFailed(__('The sync could not be queued. Please try again.'));
        }

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
