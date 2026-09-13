<?php

namespace App\Jobs;

use App\Actions\Sync\ImportWooCommerceProducts;
use App\Enums\ProductStatus;
use App\Models\WooCommerceConnection;
use App\Services\WooCommerce\Exceptions\WooCommerceRateLimitException;
use App\Services\WooCommerce\WooCommerceClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;

class SyncWooCommerceProduct implements ShouldQueue
{
    use Queueable;

    public int $tries = 120;

    public int $maxExceptions = 5;

    public int $timeout = 120;

    public function __construct(public int $deliveryId, public int $shopId) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('shop-sync:'.$this->shopId))
            ->shared()->releaseAfter(30)->expireAfter(3600)];
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 120, 300, 600];
    }

    public function handle(ImportWooCommerceProducts $import): void
    {
        $delivery = DB::table('woocommerce_webhook_deliveries')->where('id', $this->deliveryId)->first();
        if ($delivery === null || $delivery->processed_at !== null) {
            return;
        }

        $connection = WooCommerceConnection::whereKey($delivery->connection_id)->first();
        $shop = $connection?->shop()->first();
        if ($shop === null || $shop->id !== $this->shopId || ! $shop->type->supportsSync()
            || $connection->store_url !== $delivery->store_url) {
            return;
        }

        try {
            $product = (new WooCommerceClient($connection))->product((int) $delivery->product_id);
        } catch (WooCommerceRateLimitException $exception) {
            $this->release($exception->retryAfter);

            return;
        }

        DB::transaction(function () use ($product, $delivery, $shop, $import) {
            if ($product === null || $product['status'] !== 'publish' || $product['type'] !== 'simple') {
                $shop->products()->where('remote_id', $delivery->product_id)->update([
                    'status' => ProductStatus::Archived->value,
                    'last_synced_at' => now(),
                ]);
            } else {
                // Preserve known category hierarchy; full reconciliation refreshes the tree.
                $categories = $shop->categories()->whereNotNull('remote_id')->pluck('id', 'remote_id')->all();
                $import->handle($shop, [$product], now(), $categories);
            }

            DB::table('woocommerce_webhook_deliveries')->where('id', $this->deliveryId)
                ->update(['processed_at' => now(), 'updated_at' => now()]);
        });
    }
}
