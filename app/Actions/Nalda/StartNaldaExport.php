<?php

namespace App\Actions\Nalda;

use App\Enums\SyncRunStatus;
use App\Enums\SyncTrigger;
use App\Jobs\UploadNaldaProductFeed;
use App\Models\NaldaExport;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Throwable;

class StartNaldaExport
{
    /**
     * Queue an upload of the shop's product file to Nalda.
     *
     * Returns null when the shop has no usable credentials or an export is
     * already queued or in progress. Returns a failed export when the job
     * could not be queued.
     */
    public function handle(Shop $shop, SyncTrigger $trigger, ?User $user = null): ?NaldaExport
    {
        if (! $this->canExport($shop)) {
            return null;
        }

        // The unique index on active_shop_id refuses a second active export,
        // which a check followed by an insert could not.
        try {
            $export = DB::transaction(fn () => $shop->naldaExports()->create([
                'triggered_by' => $user?->id,
                'trigger' => $trigger,
                'status' => SyncRunStatus::Queued,
            ]));
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        try {
            Bus::dispatch((new UploadNaldaProductFeed($export))->afterCommit());
        } catch (Throwable $exception) {
            report($exception);

            $export->markFailed(__('The export could not be queued. Please try again.'));
        }

        return $export;
    }

    /**
     * Determine if the shop can upload to Nalda.
     */
    public function canExport(Shop $shop): bool
    {
        return $shop->naldaConnection?->hasUsableCredentials() ?? false;
    }
}
