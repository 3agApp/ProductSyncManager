<?php

namespace App\Jobs;

use App\Actions\Sync\ImportWooCommerceProducts;
use App\Enums\ProductStatus;
use App\Models\Shop;
use App\Models\WooCommerceConnection;
use App\Services\WooCommerce\Exceptions\WooCommerceRateLimitException;
use App\Services\WooCommerce\WooCommerceClient;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;

/**
 * Apply every pending webhook delivery for one connection.
 *
 * A delivery only says which product changed. The current state is always
 * fetched rather than read from the payload, a hundred products per request,
 * so a store that saves its whole catalog costs a few dozen requests instead
 * of one job and one request per delivery.
 */
class SyncWooCommerceWebhookDeliveries implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Products fetched per request, which is WooCommerce's largest page.
     */
    public const int BATCH_SIZE = 100;

    /**
     * Seconds of work before the job steps aside for the rest of the queue.
     */
    private const int TIME_BUDGET = 240;

    public int $timeout = 600;

    public int $maxExceptions = 5;

    /**
     * Hold the unique lock at most this long, in case a worker dies mid-run.
     */
    public int $uniqueFor = 3600;

    public function __construct(public int $connectionId, public int $shopId) {}

    /**
     * One job per connection: a queued job applies every delivery that
     * arrives before it runs.
     */
    public function uniqueId(): string
    {
        return (string) $this->connectionId;
    }

    /**
     * Retry by time rather than attempts. Waiting behind a full sync releases
     * the job, and those releases must not use up its attempts.
     */
    public function retryUntil(): CarbonInterface
    {
        return now()->addHours(12);
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 120, 300, 600];
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('shop-sync:'.$this->shopId))
            ->shared()->releaseAfter(30)->expireAfter(3600)];
    }

    public function handle(ImportWooCommerceProducts $import): void
    {
        $connection = WooCommerceConnection::whereKey($this->connectionId)->first();
        $shop = $connection?->shop()->first();
        if ($connection === null || $shop === null || $shop->id !== $this->shopId || ! $shop->type->supportsSync()) {
            return;
        }

        $client = new WooCommerceClient($connection);
        $startedAt = now();

        while (($productIds = $this->pendingProductIds($connection)) !== []) {
            // Deliveries that arrive after the fetch describe a newer state,
            // so only the ones already recorded are marked processed.
            $upTo = (int) $this->pending($connection)->max('id');

            try {
                $remoteProducts = $client->products($productIds);
            } catch (WooCommerceRateLimitException $exception) {
                $this->release($exception->retryAfter);

                return;
            }

            $this->apply($import, $shop, $connection, $productIds, $remoteProducts, $upTo);

            if ($startedAt->diffInSeconds(now()) >= self::TIME_BUDGET) {
                $this->release(1);

                return;
            }
        }
    }

    /**
     * Import what is still importable and archive the rest, then mark the
     * deliveries processed in the same transaction.
     *
     * @param  array<int, int>  $productIds
     * @param  array<int, array<string, mixed>>  $remoteProducts
     */
    private function apply(
        ImportWooCommerceProducts $import,
        Shop $shop,
        WooCommerceConnection $connection,
        array $productIds,
        array $remoteProducts,
        int $upTo,
    ): void {
        $importable = array_values(array_filter(
            $remoteProducts,
            fn (array $product) => $product['status'] === 'publish' && $product['type'] === 'simple',
        ));

        // A product the store no longer lists, or that is no longer a published
        // simple product, is archived exactly as a full sync would archive it.
        $archived = array_values(array_diff($productIds, array_column($importable, 'id')));

        DB::transaction(function () use ($import, $shop, $connection, $productIds, $importable, $archived, $upTo) {
            if ($importable !== []) {
                // Preserve known category hierarchy; full reconciliation refreshes the tree.
                $categories = $shop->categories()->whereNotNull('remote_id')->pluck('id', 'remote_id')->all();
                $import->handle($shop, $importable, now(), $categories);
            }

            if ($archived !== []) {
                $shop->products()->whereIn('remote_id', $archived)->update([
                    'status' => ProductStatus::Archived->value,
                    'last_synced_at' => now(),
                ]);
            }

            $this->pending($connection)
                ->whereIn('product_id', $productIds)
                ->where('id', '<=', $upTo)
                ->update(['processed_at' => now(), 'updated_at' => now()]);
        });
    }

    /**
     * The next batch of products with unprocessed deliveries, oldest first.
     *
     * @return array<int, int>
     */
    private function pendingProductIds(WooCommerceConnection $connection): array
    {
        return $this->pending($connection)
            ->select('product_id')
            ->groupBy('product_id')
            ->orderByRaw('MIN(id)')
            ->limit(self::BATCH_SIZE)
            ->pluck('product_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Unprocessed deliveries for the connection's current store.
     *
     * Deliveries recorded before the store URL changed are left alone, so a
     * connection pointed at another store never applies the old one's events.
     */
    private function pending(WooCommerceConnection $connection): Builder
    {
        return DB::table('woocommerce_webhook_deliveries')
            ->where('connection_id', $connection->id)
            ->where('store_url', $connection->store_url)
            ->whereNull('processed_at');
    }
}
