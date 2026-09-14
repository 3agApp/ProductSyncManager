<?php

namespace App\Actions\Temu;

use App\Enums\SyncRunStatus;
use App\Enums\SyncTrigger;
use App\Jobs\SyncTemuProducts;
use App\Models\Shop;
use App\Models\TemuSyncRun;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Throwable;

class StartTemuSync
{
    /**
     * Queue a sync of the shop's products to Temu.
     *
     * Returns null when the shop has no usable credentials or a run is
     * already queued or in progress. Returns a failed run when the job
     * could not be queued.
     */
    public function handle(Shop $shop, SyncTrigger $trigger, ?User $user = null): ?TemuSyncRun
    {
        if (! $this->canSync($shop)) {
            return null;
        }

        // The unique index on active_shop_id refuses a second active run,
        // which a check followed by an insert could not.
        try {
            $run = DB::transaction(fn () => $shop->temuSyncRuns()->create([
                'triggered_by' => $user?->id,
                'trigger' => $trigger,
                'status' => SyncRunStatus::Queued,
            ]));
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        try {
            Bus::dispatch((new SyncTemuProducts($run))->afterCommit());
        } catch (Throwable $exception) {
            report($exception);

            $run->markFailed(__('The Temu sync could not be queued. Please try again.'));
        }

        return $run;
    }

    /**
     * Determine if the shop can sync to Temu.
     */
    public function canSync(Shop $shop): bool
    {
        return $shop->temuConnection?->hasUsableCredentials() ?? false;
    }
}
