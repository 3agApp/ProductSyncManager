<?php

namespace App\Jobs;

use App\Actions\Temu\StartTemuSync;
use App\Enums\SyncRunStatus;
use App\Enums\SyncTrigger;
use App\Models\Shop;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sync to Temu a few minutes after webhooks change a shop's products.
 *
 * Only one waits per shop, so every change in the meantime goes out in the
 * same run, and a store saving its whole catalog costs one run every few
 * minutes rather than one per product.
 */
class QueueTemuSyncAfterWebhooks implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /**
     * Seconds to wait for more changes before syncing.
     */
    public const int DELAY_SECONDS = 300;

    /**
     * Seconds to wait before checking again on a run already in progress.
     */
    public const int RETRY_SECONDS = 60;

    /**
     * Hold the unique lock at most this long, in case a worker dies mid-run.
     */
    public int $uniqueFor = 3600;

    public function __construct(public int $shopId)
    {
        $this->delay(self::DELAY_SECONDS);
    }

    /**
     * One waiting sync per shop.
     */
    public function uniqueId(): string
    {
        return (string) $this->shopId;
    }

    /**
     * Retry by time rather than attempts. Waiting on a run in progress
     * releases the job, and those releases must not use up attempts.
     */
    public function retryUntil(): CarbonInterface
    {
        return now()->addHours(3);
    }

    public function handle(StartTemuSync $start): void
    {
        $shop = Shop::with('temuConnection')->find($this->shopId);

        // The setting may have been turned off while the job waited.
        if ($shop === null || ! ($shop->temuConnection->sync_after_sync ?? false)) {
            return;
        }

        if ($start->handle($shop, SyncTrigger::Webhook) !== null) {
            return;
        }

        // A run still queued reads the catalog when it runs, so it carries
        // these changes. One already running may have passed these products,
        // so try again once it is done.
        $running = $shop->temuSyncRuns()->where('status', SyncRunStatus::Running->value)->exists();

        if ($running) {
            $this->release(self::RETRY_SECONDS);
        }
    }
}
