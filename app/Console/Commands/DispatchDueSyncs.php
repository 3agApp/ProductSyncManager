<?php

namespace App\Console\Commands;

use App\Actions\Sync\CalculateNextSyncRun;
use App\Actions\Sync\StartSyncRun;
use App\Enums\SyncTrigger;
use App\Models\ShopSyncSetting;
use App\Models\SyncRun;
use Illuminate\Console\Command;

class DispatchDueSyncs extends Command
{
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
    public function handle(StartSyncRun $startSyncRun, CalculateNextSyncRun $calculateNextSyncRun): int
    {
        $this->reapStalledRuns();

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
    }
}
