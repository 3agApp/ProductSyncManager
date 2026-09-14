<?php

namespace App\Console\Commands;

use App\Actions\Sync\CalculateNextSyncRun;
use App\Actions\Sync\StartSyncRun;
use App\Actions\Temu\StartTemuSync;
use App\Enums\SyncRunStatus;
use App\Enums\SyncTrigger;
use App\Enums\TemuProductStatus;
use App\Models\NaldaExport;
use App\Models\ShopSyncSetting;
use App\Models\SyncRun;
use App\Models\TemuConnection;
use App\Models\TemuSyncRun;
use Illuminate\Console\Command;

class DispatchDueSyncs extends Command
{
    /**
     * Minutes between checks on Temu products that are still being processed.
     */
    private const int TEMU_CHECK_MINUTES = 15;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:dispatch-due';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Queue syncs for shops whose schedule is due, and fail runs that stalled';

    /**
     * Execute the console command.
     */
    public function handle(StartSyncRun $startSyncRun, CalculateNextSyncRun $calculateNextSyncRun, StartTemuSync $startTemuSync): int
    {
        $this->reapStalledRuns();
        $this->checkTemuProcessing($startTemuSync);

        $due = ShopSyncSetting::query()
            ->where('is_enabled', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->with('shop')
            ->get();

        foreach ($due as $settings) {
            $run = $startSyncRun->handle($settings->shop, SyncTrigger::Scheduled);

            if ($run === null) {
                $this->components->warn("Skipped shop {$settings->shop->slug}: a sync is already running or it is not configured.");
            } elseif ($run->status === SyncRunStatus::Failed) {
                $this->components->error("Could not queue a sync for shop {$settings->shop->slug}.");
            }

            // Always advance from now rather than from the missed slot, so an
            // outage cannot queue up a burst of catch-up runs.
            $settings->update([
                'next_run_at' => $calculateNextSyncRun->handle($settings, now()),
            ]);
        }

        $this->components->info("Dispatched {$due->count()} scheduled sync(s).");

        return self::SUCCESS;
    }

    /**
     * Fail runs whose worker died without ever finishing them.
     */
    private function reapStalledRuns(): void
    {
        SyncRun::query()
            ->active()
            ->where('created_at', '<', now()->subMinutes(SyncRun::ACTIVE_TIMEOUT_MINUTES))
            ->get()
            ->each(fn (SyncRun $run) => $run->markFailed(__('The sync timed out.')));

        NaldaExport::query()
            ->active()
            ->where('created_at', '<', now()->subMinutes(NaldaExport::ACTIVE_TIMEOUT_MINUTES))
            ->get()
            ->each(fn (NaldaExport $export) => $export->markFailed(__('The upload to Nalda timed out.')));

        TemuSyncRun::query()
            ->active()
            ->where('created_at', '<', now()->subMinutes(TemuSyncRun::ACTIVE_TIMEOUT_MINUTES))
            ->get()
            ->each(fn (TemuSyncRun $run) => $run->markFailed(__('The Temu sync timed out.')));
    }

    /**
     * Sync shops whose Temu products are still being processed.
     *
     * Temu creates, reviews and prices a product in the background, and
     * stock and price can only be sent once it has, so a shop with products
     * still in that state is synced again every so often until they settle.
     */
    private function checkTemuProcessing(StartTemuSync $start): void
    {
        $due = now()->subMinutes(self::TEMU_CHECK_MINUTES);

        TemuConnection::query()
            ->with('shop')
            ->whereHas('shop.temuProducts', fn ($query) => $query
                ->where('status', TemuProductStatus::Processing->value)
                ->where(fn ($query) => $query->whereNull('checked_at')->orWhere('checked_at', '<', $due)))
            ->whereDoesntHave('shop.temuSyncRuns', fn ($query) => $query->where('created_at', '>', $due))
            ->get()
            ->each(fn (TemuConnection $connection) => $start->handle($connection->shop, SyncTrigger::Scheduled));
    }
}
