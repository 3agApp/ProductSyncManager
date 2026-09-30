<?php

namespace App\Jobs;

use App\Actions\Temu\BuildTemuProductPayload;
use App\Enums\TemuProductStatus;
use App\Models\Product;
use App\Models\TemuConnection;
use App\Models\TemuProduct;
use App\Models\TemuSyncRun;
use App\Services\Temu\TemuClient;
use App\Services\Temu\TemuException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Bring what Temu lists for a shop in line with the catalog.
 *
 * Each run first reads back where the products already sent stand on
 * Temu, then walks the catalog: products chosen for Temu are created or
 * have their stock, price and details updated, products no longer chosen
 * are taken off sale, and so are products deleted from the catalog.
 */
class SyncTemuProducts implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     *
     * Waiting for a running WooCommerce sync releases the job, which counts
     * as an attempt, so this is high and maxExceptions bounds real failures.
     */
    public int $tries = 120;

    /**
     * The number of seconds the job may run before timing out.
     */
    public int $timeout = 3600;

    /**
     * The number of unhandled exceptions to allow before failing.
     */
    public int $maxExceptions = 3;

    /**
     * Products read per query, and Temu products checked per status request.
     */
    private const int CHUNK_SIZE = 100;

    /**
     * Seconds to wait before carrying on after Temu limits requests.
     */
    private const int RATE_LIMIT_DELAY = 30;

    /**
     * The reason sent with a price change.
     */
    private const string PRICE_REASON = 'Price updated in the shop';

    /**
     * Temu's codes for a recommended retail price not above the base price.
     */
    private const array LIST_PRICE_TOO_LOW = [150010041, 150010083];

    /**
     * Counters accumulated across the run.
     *
     * @var array<string, int>
     */
    private array $counters = [
        'created' => 0,
        'updated' => 0,
        'removed' => 0,
        'failed' => 0,
        'skipped' => 0,
    ];

    private TemuClient $client;

    public function __construct(public TemuSyncRun $run)
    {
        //
    }

    /**
     * Get the middleware the job should pass through.
     *
     * Sharing the WooCommerce sync's lock means products are never sent
     * from a catalog a sync is halfway through rewriting.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('shop-sync:'.$this->run->shop_id))
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
        return [60, 300];
    }

    /**
     * Sync the shop's products to Temu.
     */
    public function handle(BuildTemuProductPayload $payloads): void
    {
        // A retry can arrive after the stalled-run reaper failed this one.
        if ($this->run->refresh()->status->isFinished()) {
            return;
        }

        $connection = $this->run->shop->temuConnection;

        if ($connection === null || ! $connection->hasUsableCredentials()) {
            $this->fail(TemuException::authentication());

            return;
        }

        $this->run->markRunning();
        $this->client = new TemuClient($connection);

        try {
            $this->refreshStatuses($connection);
            $this->syncCatalog($connection, $payloads);
            $this->removeDeletedProducts();
        } catch (TemuException $exception) {
            $this->persistProgress();

            if (! $exception->isRetryable()) {
                $this->fail($exception);

                return;
            }

            // Work already done is recorded on each product, so the next
            // attempt only sends what is still different.
            if ($exception->isRateLimited()) {
                $this->release(self::RATE_LIMIT_DELAY);

                return;
            }

            throw $exception;
        }

        $this->persistProgress();
        $connection->update(['last_synced_at' => now()]);
        $this->run->markCompleted();
    }

    /**
     * Read back where every product already on Temu stands.
     */
    private function refreshStatuses(TemuConnection $connection): void
    {
        $connection->shop->temuProducts()
            ->whereNotNull('goods_id')
            ->where('status', '!=', TemuProductStatus::Deleted->value)
            ->chunkById(self::CHUNK_SIZE, function (Collection $records) {
                $result = $this->client->call('bg.local.goods.list.query', [
                    'pageNo' => 1,
                    'pageSize' => self::CHUNK_SIZE,
                    'goodsIdList' => $records->pluck('goods_id')->map(fn ($id) => (int) $id)->values()->all(),
                ]);

                $goods = collect(is_array($result['goodsList'] ?? null) ? $result['goodsList'] : [])
                    ->filter(fn (mixed $item) => is_array($item) && isset($item['goodsId']))
                    ->keyBy(fn (array $item) => (int) $item['goodsId']);

                foreach ($records as $record) {
                    /** @var TemuProduct $record */
                    $item = $goods->get((int) $record->goods_id);

                    if ($item !== null) {
                        $this->applyTemuState($record, $item);
                    }
                }
            });
    }

    /**
     * Record what Temu reports for one product.
     *
     * @param  array<string, mixed>  $item
     */
    private function applyTemuState(TemuProduct $record, array $item): void
    {
        $subStatus = is_numeric($item['goodsShowSubStatus'] ?? null) ? (int) $item['goodsShowSubStatus'] : null;
        $skuId = $item['skuInfoList'][0]['skuId'] ?? ($item['skuIdList'][0] ?? null);

        $attributes = [
            'checked_at' => now(),
            'sku_id' => is_numeric($skuId) ? (int) $skuId : $record->sku_id,
        ];

        if ($subStatus !== null) {
            $status = TemuProductStatus::fromTemuSubStatus($subStatus);

            $attributes['temu_sub_status'] = $subStatus;
            $attributes['status'] = $status;

            // A product Temu now reports on is past whatever request failed
            // before, unless it still cannot be published.
            if (! $status->needsAttention()) {
                $attributes['message'] = null;
            }
        }

        $record->update($attributes);
    }

    /**
     * Walk the catalog and send each product what it needs.
     */
    private function syncCatalog(TemuConnection $connection, BuildTemuProductPayload $payloads): void
    {
        $connection->shop->products()
            ->with(['temuProduct', 'brand', 'categories'])
            ->chunkById(self::CHUNK_SIZE, function (Collection $products) use ($connection, $payloads) {
                foreach ($products as $product) {
                    /** @var Product $product */
                    $this->syncProduct($connection, $payloads, $product);
                }

                $this->persistProgress();
            });
    }

    /**
     * Create, update or take one product off sale.
     *
     * A refusal of this one product is recorded against it and the run
     * carries on; a problem with the connection stops the run.
     */
    private function syncProduct(TemuConnection $connection, BuildTemuProductPayload $payloads, Product $product): void
    {
        $record = $product->temuProduct;
        $listed = $connection->lists($product);

        if (! $listed) {
            if ($record !== null && $this->takeOffSale($record)) {
                $this->counters['removed']++;
            }

            return;
        }

        try {
            if ($record === null || ! $record->existsOnTemu()) {
                $this->create($connection, $payloads, $product, $record);

                return;
            }

            $this->update($connection, $payloads, $product, $record);
        } catch (TemuException $exception) {
            if ($exception->isConnectionProblem()) {
                throw $exception;
            }

            $record = $product->temuProduct()->first();

            // A product Temu turned away over a passing fault of its own is
            // left pending, so the next run sends it again unprompted.
            $record?->update([
                'status' => match (true) {
                    $record->existsOnTemu() => $record->status,
                    $exception->isRetryable() => TemuProductStatus::Pending,
                    default => TemuProductStatus::Failed,
                },
                'message' => $exception->userMessage(),
            ]);

            $this->counters['failed']++;
        }
    }

    /**
     * Create the product on Temu.
     *
     * A product Temu refused is only sent again once it has been edited or
     * someone asks for it to be retried, so an unfixable product does not
     * cost a request on every run.
     */
    private function create(TemuConnection $connection, BuildTemuProductPayload $payloads, Product $product, ?TemuProduct $record): void
    {
        if ($record?->status === TemuProductStatus::Failed
            && $product->updated_at !== null
            && $record->updated_at !== null
            && $product->updated_at->lte($record->updated_at)) {
            $this->counters['skipped']++;

            return;
        }

        if ($record === null) {
            $record = new TemuProduct([
                'product_id' => $product->id,
                'external_goods_id' => TemuProduct::externalGoodsId($product),
                'external_sku_id' => $payloads->externalSkuId($product),
                'status' => TemuProductStatus::Pending,
            ]);

            // The shop is never taken from input, so it is not fillable.
            $record->shop_id = $product->shop_id;
            $record->save();
        }

        $result = $this->client->call('temu.local.goods.v3.add', $payloads->forCreate($connection, $product, $record));

        $record->update([
            'goods_id' => is_numeric($result['goodsId'] ?? null) ? (int) $result['goodsId'] : null,
            'status' => TemuProductStatus::Processing,
            'temu_sub_status' => null,
            'message' => null,
            'sent_price' => $connection->price($product),
            'sent_list_price' => $connection->listPrice($product),
            'sent_stock' => $payloads->stock($product),
            'content_hash' => $payloads->contentHash($product),
            'on_sale' => true,
            'submitted_at' => now(),
        ]);

        $product->setRelation('temuProduct', $record);
        $this->counters['created']++;
    }

    /**
     * Send whatever changed since the product was last sent.
     *
     * Temu refuses changes while it is still processing or pricing a
     * product, and stock and price need the SKU id it only reports once
     * the product is processed, so those wait for a later run.
     */
    private function update(TemuConnection $connection, BuildTemuProductPayload $payloads, Product $product, TemuProduct $record): void
    {
        if ($record->status->refusesUpdates() || $record->goods_id === null) {
            $this->counters['skipped']++;

            return;
        }

        $changed = false;

        if (! $record->on_sale) {
            $this->client->call('bg.local.goods.sale.status.set', ['goodsId' => $record->goods_id, 'onsale' => 1]);
            $record->update(['on_sale' => true]);
            $changed = true;
        }

        $stock = $payloads->stock($product);

        if ($record->sku_id !== null && $record->sent_stock !== $stock) {
            $this->client->call('bg.local.goods.stock.edit', [
                'goodsId' => $record->goods_id,
                'skuStockTargetList' => [['skuId' => $record->sku_id, 'stockTarget' => $stock]],
            ]);
            $record->update(['sent_stock' => $stock]);
            $changed = true;
        }

        $price = $connection->price($product);

        if ($record->sku_id !== null && $record->sent_price !== $price) {
            $this->changePrice($connection, $record, $price);
            $changed = true;
        }

        $listPrice = $payloads->listPrice($connection, $product);

        if ($record->sku_id !== null && $listPrice !== null && $record->sent_list_price !== $listPrice['amount']) {
            $changed = $this->changeListPrice($record, $listPrice) || $changed;
        }

        $hash = $payloads->contentHash($product);

        if ($record->content_hash !== $hash) {
            $this->client->call('bg.local.goods.partial.update', $this->contentUpdate($payloads, $product, $record));
            $record->update(['content_hash' => $hash]);
            $changed = true;
        }

        if ($changed) {
            $record->update(['message' => null]);
            $this->counters['updated']++;
        }
    }

    /**
     * Ask Temu to change the product's base price.
     *
     * Temu answers a price change per SKU, so a refused SKU is an error
     * even when the request itself succeeded.
     */
    private function changePrice(TemuConnection $connection, TemuProduct $record, string $price): void
    {
        $result = $this->client->call('bg.local.goods.priceorder.change.sku.price', [
            'goodsId' => $record->goods_id,
            'changeSkuPriceDTOList' => [[
                'reason' => self::PRICE_REASON,
                'skuChangePriceBaseDTOList' => [[
                    'skuId' => $record->sku_id,
                    'newSupplierPrice' => ['amount' => $price, 'currency' => $connection->currency],
                ]],
            ]],
        ]);

        $failed = array_map('intval', is_array($result['failedSkuList'] ?? null) ? $result['failedSkuList'] : []);

        if (in_array((int) $record->sku_id, $failed, true)) {
            $reasons = is_array($result['failedSkuReasonMap'] ?? null) ? $result['failedSkuReasonMap'] : [];

            throw TemuException::rejected((string) ($reasons[(string) $record->sku_id] ?? __('Temu did not accept the new price.')));
        }

        $record->update(['sent_price' => $price]);
    }

    /**
     * Send the product's recommended retail price.
     *
     * Temu only takes one above the base price it currently has, and a
     * lower base price waits on Temu's review of the price change. Until
     * then the retail price is left for a later run, which sends it again
     * because it is not recorded as sent.
     *
     * @param  array{amount: string, currency: string}  $listPrice
     */
    private function changeListPrice(TemuProduct $record, array $listPrice): bool
    {
        try {
            $this->client->call('bg.local.goods.partial.update', [
                'goodsId' => $record->goods_id,
                'skuList' => [['skuId' => $record->sku_id, 'listPrice' => $listPrice]],
            ]);
        } catch (TemuException $exception) {
            if (in_array($exception->temuCode(), self::LIST_PRICE_TOO_LOW, true)) {
                return false;
            }

            throw $exception;
        }

        $record->update(['sent_list_price' => $listPrice['amount']]);

        return true;
    }

    /**
     * Build the partial update carrying the product's content.
     *
     * Temu wants between 3 and 10 carousel images when they are changed, so
     * fewer are left as Temu already has them.
     *
     * @return array<string, mixed>
     */
    private function contentUpdate(BuildTemuProductPayload $payloads, Product $product, TemuProduct $record): array
    {
        $content = $payloads->content($product);

        $basic = ['goodsName' => $content['name']];

        if (count($content['images']) >= 3) {
            $basic['goodsGallery'] = ['goodsCarouselImage' => $content['images']];
        }

        return array_filter([
            'goodsId' => $record->goods_id,
            'goodsBasic' => $basic,
            'goodsDesc' => $content['description'] === '' ? null : $content['description'],
        ], fn (mixed $value) => $value !== null);
    }

    /**
     * Take products deleted from the catalog off sale.
     */
    private function removeDeletedProducts(): void
    {
        $this->run->shop->temuProducts()
            ->whereNull('product_id')
            ->where('on_sale', true)
            ->whereNotNull('goods_id')
            ->chunkById(self::CHUNK_SIZE, function (Collection $records) {
                foreach ($records as $record) {
                    /** @var TemuProduct $record */
                    if ($this->takeOffSale($record)) {
                        $this->counters['removed']++;
                    }
                }
            });
    }

    /**
     * Take a product off sale on Temu, if it is on sale there.
     *
     * Returns whether a request was made. A refusal is recorded against
     * the product rather than stopping the run.
     */
    private function takeOffSale(TemuProduct $record): bool
    {
        if (! $record->existsOnTemu() || ! $record->on_sale || $record->status === TemuProductStatus::Deleted) {
            return false;
        }

        try {
            $this->client->call('bg.local.goods.sale.status.set', ['goodsId' => $record->goods_id, 'onsale' => 0]);
        } catch (TemuException $exception) {
            if ($exception->isConnectionProblem()) {
                throw $exception;
            }

            $record->update(['message' => $exception->userMessage()]);
            $this->counters['failed']++;

            return false;
        }

        $record->update([
            'on_sale' => false,
            'status' => TemuProductStatus::Inactive,
            'message' => null,
        ]);

        return true;
    }

    /**
     * Write the counters to the run.
     */
    private function persistProgress(): void
    {
        $this->run->update([
            'created_products' => $this->counters['created'],
            'updated_products' => $this->counters['updated'],
            'removed_products' => $this->counters['removed'],
            'failed_products' => $this->counters['failed'],
            'skipped_products' => $this->counters['skipped'],
        ]);
    }

    /**
     * Record the failure against the run so the user can see what happened.
     */
    public function failed(?Throwable $exception): void
    {
        $message = $exception instanceof TemuException
            ? $exception->userMessage()
            : __('The Temu sync failed unexpectedly. Please try again.');

        $this->run->refresh()->markFailed($message);
    }
}
