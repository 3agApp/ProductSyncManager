<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneWebhookDeliveries extends Command
{
    /**
     * Deliveries still unprocessed after this many days are dropped too.
     */
    private const int STALE_AFTER_DAYS = 30;

    private const int BATCH_SIZE = 1000;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'webhooks:prune-deliveries {--days=7 : Keep processed deliveries for this many days}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete processed webhook deliveries, and unprocessed ones too old to matter';

    /**
     * Execute the console command.
     *
     * A processed delivery is only kept to recognise the same delivery arriving
     * twice, which WooCommerce does within minutes, not days. One still
     * unprocessed after a month belongs to a store the connection no longer
     * points at, and the scheduled full sync has long since reconciled the
     * catalog either way.
     */
    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $deleted = 0;

        do {
            // Small batches keep each delete short while webhooks keep arriving.
            $batch = DB::table('woocommerce_webhook_deliveries')
                ->where(fn ($query) => $query
                    ->where('processed_at', '<', now()->subDays($days))
                    ->orWhere('created_at', '<', now()->subDays(self::STALE_AFTER_DAYS)))
                ->limit(self::BATCH_SIZE)
                ->delete();

            $deleted += $batch;
        } while ($batch === self::BATCH_SIZE);

        $this->components->info("Pruned {$deleted} webhook deliveries.");

        return self::SUCCESS;
    }
}
