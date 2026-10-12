<?php

namespace App\Console\Commands;

use App\Actions\Orders\AdoptWooCommerceOrders as Adopt;
use App\Models\Shop;
use App\Services\WooCommerce\Exceptions\WooCommerceException;
use Illuminate\Console\Command;

class AdoptWooCommerceOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'marketplace:adopt-woocommerce-orders
        {shop : The shop slug}
        {--days=180 : How many days of store orders to read}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Record the Nalda and Temu orders a store already has, so they are never imported again';

    /**
     * Execute the console command.
     *
     * Reads the store; changes nothing in it. Nalda's first import does the
     * same by itself, so this is for checking the count before switching on.
     */
    public function handle(Adopt $adopt): int
    {
        $shop = Shop::where('slug', $this->argument('shop'))->first();

        if ($shop?->wooCommerceConnection === null) {
            $this->components->error('No shop with that slug has a WooCommerce connection.');

            return self::FAILURE;
        }

        try {
            $count = $adopt->handle($shop, now()->subDays(max(1, (int) $this->option('days'))));
        } catch (WooCommerceException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Recorded {$count} new marketplace order(s) for {$shop->slug}; {$shop->marketplaceOrders()->count()} in total.");

        return self::SUCCESS;
    }
}
