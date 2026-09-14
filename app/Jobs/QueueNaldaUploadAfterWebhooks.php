<?php

namespace App\Jobs;

use App\Actions\Nalda\StartNaldaExport;
use App\Enums\SyncRunStatus;
use App\Enums\SyncTrigger;
use App\Models\Shop;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Upload to Nalda a few minutes after webhooks change a shop's products.
 *
 * Only one waits per shop, so every change in the meantime goes out in the
 * same file, and a store saving its whole catalog costs one upload every few
 * minutes rather than one per product.
 */
class QueueNaldaUploadAfterWebhooks implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /**
     * Seconds to wait for more changes before uploading.
     */
    public const int DELAY_SECONDS = 300;

    /**
     * Seconds to wait before checking again on an upload already building.
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
     * One waiting upload per shop.
     */
    public function uniqueId(): string
    {
        return (string) $this->shopId;
    }

    /**
     * Retry by time rather than attempts. Waiting on an upload that is
     * building releases the job, and those releases must not use up attempts.
     */
    public function retryUntil(): CarbonInterface
    {
        return now()->addHours(2);
    }

    public function handle(StartNaldaExport $start): void
    {
        $shop = Shop::with('naldaConnection')->find($this->shopId);

        // The setting may have been turned off while the job waited.
        if ($shop === null || ! ($shop->naldaConnection->upload_after_sync ?? false)) {
            return;
        }

        if ($start->handle($shop, SyncTrigger::Webhook) !== null) {
            return;
        }

        // An upload still queued builds its file from the catalog as it is
        // when it runs, so it carries these changes. One already building may
        // have read the catalog before them, so try again once it is done.
        $building = $shop->naldaExports()->where('status', SyncRunStatus::Running->value)->exists();

        if ($building) {
            $this->release(self::RETRY_SECONDS);
        }
    }
}
