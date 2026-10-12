<?php

namespace App\Console\Commands;

use App\Actions\Nalda\CorrectLegacyNaldaTotals as Correct;
use App\Models\Shop;
use App\Services\Nalda\NaldaApiException;
use App\Services\WooCommerce\Exceptions\WooCommerceException;
use Illuminate\Console\Command;

class CorrectLegacyNaldaTotals extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nalda:correct-legacy-totals
        {shop : The shop slug}
        {--days=730 : How many days of store orders to read}
        {--apply : Change the orders; without it, only report what would change}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Price the Nalda orders the old Nalda plugin imported at Nalda's payout";

    /**
     * Execute the console command.
     */
    public function handle(Correct $correct): int
    {
        $shop = Shop::where('slug', $this->argument('shop'))->first();

        if ($shop?->wooCommerceConnection === null || $shop->naldaConnection?->hasUsableApiKey() !== true) {
            $this->components->error('No shop with that slug has both a WooCommerce connection and a Nalda API key.');

            return self::FAILURE;
        }

        try {
            $results = $correct->handle($shop, now()->subDays(max(1, (int) $this->option('days'))), (bool) $this->option('apply'));
        } catch (WooCommerceException|NaldaApiException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['WooCommerce', 'Nalda', 'Total', 'Payout', 'Result'],
            array_map(fn (array $row) => [$row['woocommerce_id'], $row['nalda_id'], $row['from'], $row['to'] ?? '', $row['result']], $results),
        );

        $this->components->info(collect($results)->countBy('result')->map(fn (int $count, string $result) => "{$result}: {$count}")->implode(', ') ?: 'No Nalda orders found.');

        return self::SUCCESS;
    }
}
