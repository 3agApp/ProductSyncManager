<?php

namespace App\Jobs;

use App\Actions\Sync\ImportWooCommerceCategories;
use App\Actions\Sync\ImportWooCommerceProducts;
use App\Enums\ProductStatus;
use App\Models\Shop;
use App\Models\SyncRun;
use App\Services\WooCommerce\Exceptions\WooCommerceAuthenticationException;
use App\Services\WooCommerce\Exceptions\WooCommerceDestinationException;
use App\Services\WooCommerce\Exceptions\WooCommerceException;
use App\Services\WooCommerce\Exceptions\WooCommerceRateLimitException;
use App\Services\WooCommerce\Exceptions\WooCommerceRequestException;
use App\Services\WooCommerce\WooCommerceClient;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class SyncWooCommerceProducts implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 120;

    /**
     * The number of seconds the job may run before timing out.
     */
    public int $timeout = 900;

    /**
     * The number of unhandled exceptions to allow before failing.
     */
    public int $maxExceptions = 2;

    /**
     * Counters accumulated across every page of the run.
     *
     * @var array<string, int>
     */
    private array $counters = [
        'processed' => 0,
        'created' => 0,
        'updated' => 0,
        'archived' => 0,
        'skipped' => 0,
    ];

    private ?int $totalProducts = null;

    /**
     * Local category ids from this run, keyed by remote id.
     *
     * @var array<int, int>
     */
    private array $categoryIds = [];

    public function __construct(public SyncRun $syncRun)
    {
        //
    }

    /**
     * Get the middleware the job should pass through.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('shop-sync:'.$this->syncRun->shop_id))
                ->shared()
                ->releaseAfter(30)
                ->expireAfter(3600),
        ];
    }

    /**
     * Get the number of seconds to wait before retrying.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    /**
     * Pull the shop's catalog from WooCommerce.
     */
    public function handle(ImportWooCommerceProducts $import, ImportWooCommerceCategories $importCategories): void
    {
        // A retry can arrive after the stalled-run reaper failed this run and a
        // newer run took the shop's slot. Reviving it would collide with that run.
        if ($this->syncRun->refresh()->status->isFinished()) {
            return;
        }

        $shop = $this->syncRun->shop;
        $connection = $shop->wooCommerceConnection;

        if ($connection === null || ! $connection->hasUsableCredentials()) {
            $this->fail(new WooCommerceAuthenticationException('The shop has no usable WooCommerce credentials.'));

            return;
        }

        $this->syncRun->markRunning();

        $runStartedAt = $this->syncRun->started_at ?? now();
        $client = new WooCommerceClient($connection);

        try {
            // Variable products cannot be represented as single local products,
            // so they are reported as skipped rather than silently ignored.
            $this->counters['skipped'] = $client->variableProductCount();
            $this->persistProgress();

            // The tree is read first so products can be filed under it as
            // they arrive, with the parent links the product payload omits.
            $this->categoryIds = $this->importCategories($client, $importCategories, $shop);

            $this->importAllPages($client, $import, $shop, $runStartedAt);

            if ($this->totalProducts !== null && $this->counters['processed'] !== $this->totalProducts) {
                throw new WooCommerceRequestException('The product count changed during sync; archiving was cancelled.');
            }

            // Only archive once every page succeeded. Archiving after a partial
            // failure would wipe the catalog the store still has.
            $this->counters['archived'] = $this->archiveMissing($shop, $runStartedAt);
            $this->persistProgress();

            $this->syncRun->markCompleted();
            $shop->syncSettings?->update(['last_run_at' => now()]);
        } catch (WooCommerceRateLimitException $exception) {
            $this->persistProgress();
            $this->release($exception->retryAfter);
        } catch (WooCommerceAuthenticationException|WooCommerceDestinationException $exception) {
            // Bad credentials and a blocked address will not fix themselves;
            // do not burn the retries.
            $this->persistProgress();
            $this->fail($exception);
        }
    }

    /**
     * Read the store's category tree into the shop.
     *
     * @return array<int, int> local category id, keyed by remote id
     */
    private function importCategories(
        WooCommerceClient $client,
        ImportWooCommerceCategories $import,
        Shop $shop,
    ): array {
        $maxPages = max(1, (int) config('services.woocommerce.max_pages'));
        $remoteCategories = [];

        for ($page = 1; $page <= $maxPages; $page++) {
            $remotePage = $client->categoryPage($page);

            if ($remotePage->isEmpty()) {
                if ($remotePage->totalPages !== null && $page < $remotePage->totalPages) {
                    throw new WooCommerceRequestException('The category catalog ended before all pages were received.');
                }

                return $import->handle($shop, $remoteCategories);
            }

            $remoteCategories = [...$remoteCategories, ...$remotePage->categories];

            if ($remotePage->totalPages !== null && $page >= $remotePage->totalPages) {
                return $import->handle($shop, $remoteCategories);
            }
        }

        throw new WooCommerceRequestException('The category catalog exceeded the configured page limit.');
    }

    /**
     * Walk every page of remote products.
     */
    private function importAllPages(
        WooCommerceClient $client,
        ImportWooCommerceProducts $import,
        Shop $shop,
        CarbonInterface $runStartedAt,
    ): void {
        $maxPages = max(1, (int) config('services.woocommerce.max_pages'));

        for ($page = 1; $page <= $maxPages; $page++) {
            $remotePage = $client->productPage($page);

            if ($page === 1) {
                $this->totalProducts = $remotePage->total;
            }

            if ($remotePage->isEmpty()) {
                if (($remotePage->totalPages !== null && $page < $remotePage->totalPages)
                    || ($this->totalProducts !== null && $this->counters['processed'] < $this->totalProducts)) {
                    throw new WooCommerceRequestException('The product catalog ended before all products were received.');
                }

                return;
            }

            $result = $import->handle($shop, $remotePage->products, $runStartedAt, $this->categoryIds);

            $this->counters['created'] += $result->created;
            $this->counters['updated'] += $result->updated;
            $this->counters['skipped'] += $result->skipped;
            $this->counters['processed'] += count($remotePage->products);

            $this->persistProgress();

            if ($remotePage->totalPages !== null && $page >= $remotePage->totalPages) {
                return;
            }
        }

        throw new WooCommerceRequestException('The product catalog exceeded the configured page limit; archiving was cancelled.');
    }

    /**
     * Archive products the store no longer has.
     *
     * Only previously synced products are touched, so anything created by
     * hand in this app is left alone.
     */
    private function archiveMissing(Shop $shop, CarbonInterface $runStartedAt): int
    {
        return $shop->products()
            ->whereNotNull('remote_id')
            ->where('status', '!=', ProductStatus::Archived->value)
            ->where(fn ($query) => $query
                ->whereNull('last_synced_at')
                ->orWhere('last_synced_at', '<', $runStartedAt))
            ->update([
                'status' => ProductStatus::Archived->value,
                'updated_at' => now(),
            ]);
    }

    /**
     * Write the counters to the run.
     *
     * This is called once per page rather than once per product, which keeps
     * write pressure off the database while the browser polls the run.
     */
    private function persistProgress(): void
    {
        $this->syncRun->update([
            'total_products' => $this->totalProducts,
            'processed_products' => $this->counters['processed'],
            'created_products' => $this->counters['created'],
            'updated_products' => $this->counters['updated'],
            'archived_products' => $this->counters['archived'],
            'skipped_products' => $this->counters['skipped'],
        ]);
    }

    /**
     * Record the failure against the run so the user can see what happened.
     */
    public function failed(?Throwable $exception): void
    {
        $message = $exception instanceof WooCommerceException
            ? $exception->userMessage()
            : __('The sync failed unexpectedly. Please try again.');

        $this->syncRun->refresh()->markFailed($message);
    }
}
