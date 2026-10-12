<?php

namespace App\Jobs;

use App\Actions\Orders\CreateWooCommerceOrder;
use App\Actions\Orders\RefreshMarketplaceOrderStatuses;
use App\Actions\Temu\BuildTemuWooCommerceOrder;
use App\Actions\Temu\ConfirmTemuShipments;
use App\Enums\Marketplace;
use App\Enums\MarketplaceOrderStatus;
use App\Models\MarketplaceOrder;
use App\Models\Shop;
use App\Models\TemuConnection;
use App\Services\Temu\TemuClient;
use App\Services\Temu\TemuException;
use App\Services\WooCommerce\Exceptions\WooCommerceException;
use App\Services\WooCommerce\Exceptions\WooCommerceRateLimitException;
use App\Services\WooCommerce\WooCommerceClient;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/**
 * Bring a shop's new Temu orders into WooCommerce.
 *
 * WooCommerce fulfils the orders and owns the stock: creating the order
 * there lowers the stock, which reaches Temu through the usual product sync.
 * Orders updated on Temu since the last check are read, unshipped ones are
 * created in WooCommerce once, and imported ones Temu cancels are cancelled
 * there too, which puts their stock back. When the shop confirms shipments,
 * imported orders that have Planzer's parcel number in WooCommerce are then
 * confirmed as shipped on Temu.
 */
class ImportTemuOrders implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Orders asked for per page of Temu's order list.
     */
    private const int PAGE_SIZE = 50;

    /**
     * The most pages read in one run.
     */
    private const int MAX_PAGES = 40;

    /**
     * Minutes each check reaches back before the last one, so an order Temu
     * updated while the last check ran is not missed.
     */
    private const int OVERLAP_MINUTES = 30;

    public int $tries = 3;

    public int $timeout = 600;

    /**
     * Hold the unique lock at most this long, in case a worker dies mid-run.
     */
    public int $uniqueFor = 900;

    public function __construct(public int $shopId) {}

    /**
     * One job per shop, so an order is never imported by two at once.
     */
    public function uniqueId(): string
    {
        return (string) $this->shopId;
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(BuildTemuWooCommerceOrder $build, CreateWooCommerceOrder $create, RefreshMarketplaceOrderStatuses $refresh, ConfirmTemuShipments $confirm): void
    {
        $shop = Shop::find($this->shopId);
        $connection = $shop?->temuConnection;

        if ($shop === null || $connection === null || ! self::canImport($connection)) {
            return;
        }

        $temu = new TemuClient($connection);
        $client = new WooCommerceClient($connection->shop->wooCommerceConnection);
        $startedAt = now();

        try {
            $orders = $this->updatedOrders($temu, $connection);

            foreach ($orders as $order) {
                $this->record($shop, $order);
            }

            $this->cancelImported($shop, $client);
            $this->importPending($shop, $temu, $client, $build, $create, $orders);

            if ($connection->confirm_shipments) {
                $refresh->handle($shop);
                $confirm->handle($shop, $temu, $client);
            }
        } catch (TemuException $exception) {
            if ($exception->isRateLimited()) {
                $this->release(60);

                return;
            }

            throw $exception;
        } catch (WooCommerceRateLimitException $exception) {
            $this->release($exception->retryAfter);

            return;
        }

        $connection->update(['orders_checked_at' => $startedAt]);
    }

    /**
     * Determine if a shop is set up to import Temu orders.
     */
    public static function canImport(TemuConnection $connection): bool
    {
        return $connection->import_orders
            && $connection->hasUsableCredentials()
            && $connection->shop->wooCommerceConnection?->hasUsableCredentials() === true;
    }

    /**
     * Read the orders Temu has updated since the last check.
     *
     * The first check has no window, so it reads every order the store has.
     *
     * @return array<string, array<string, mixed>> Orders by parent order number.
     */
    private function updatedOrders(TemuClient $temu, TemuConnection $connection): array
    {
        $window = $connection->orders_checked_at === null ? [] : [
            'updateAtStart' => $connection->orders_checked_at->subMinutes(self::OVERLAP_MINUTES)->getTimestamp(),
            'updateAtEnd' => now()->getTimestamp(),
        ];

        $orders = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $result = $temu->call('bg.order.list.v2.get', [
                ...$window,
                'pageNumber' => $page,
                'pageSize' => self::PAGE_SIZE,
            ]);

            $items = is_array($result['pageItems'] ?? null) ? $result['pageItems'] : [];

            foreach ($items as $item) {
                $sn = is_array($item) ? ($item['parentOrderMap']['parentOrderSn'] ?? null) : null;

                if (is_string($sn) && $sn !== '') {
                    $orders[$sn] = $item;
                }
            }

            if (count($items) < self::PAGE_SIZE || count($orders) >= (int) ($result['totalItemNum'] ?? 0)) {
                break;
            }
        }

        return $orders;
    }

    /**
     * Remember an order Temu reported and follow its status.
     *
     * An order seen for the first time after it was already cancelled,
     * shipped or delivered was handled without this app, so it is left out.
     *
     * @param  array<string, mixed>  $order
     */
    private function record(Shop $shop, array $order): void
    {
        $parent = $order['parentOrderMap'];
        $status = (int) ($parent['parentOrderStatus'] ?? 0);
        $row = $shop->marketplaceOrders()->forMarketplace(Marketplace::Temu)->where('external_order_id', $parent['parentOrderSn'])->first();

        if ($row === null) {
            if (! in_array($status, [MarketplaceOrder::TEMU_PENDING, MarketplaceOrder::TEMU_UNSHIPPED], true)) {
                return;
            }

            $shop->marketplaceOrders()->create([
                'marketplace' => Marketplace::Temu,
                'external_order_id' => $parent['parentOrderSn'],
                'marketplace_status' => (string) $status,
                'status' => MarketplaceOrderStatus::Pending,
                'ordered_at' => $this->time($parent['parentOrderTime'] ?? null),
                'ship_by_at' => $this->time($parent['expectShipLatestTime'] ?? null),
            ]);

            return;
        }

        $row->marketplace_status = (string) $status;
        $row->ship_by_at = $this->time($parent['expectShipLatestTime'] ?? null) ?? $row->ship_by_at;

        if ($status === MarketplaceOrder::TEMU_CANCELLED && in_array($row->status, [MarketplaceOrderStatus::Pending, MarketplaceOrderStatus::Failed], true)) {
            $row->status = MarketplaceOrderStatus::Cancelled;
            $row->message = null;
        }

        $row->save();
    }

    /**
     * Cancel in WooCommerce the imported orders Temu has cancelled.
     *
     * WooCommerce puts the stock back when an order is cancelled. One that
     * cannot be cancelled now is tried again on the next check.
     */
    private function cancelImported(Shop $shop, WooCommerceClient $client): void
    {
        $shop->marketplaceOrders()->forMarketplace(Marketplace::Temu)
            ->where('status', MarketplaceOrderStatus::Imported->value)
            ->where('marketplace_status', (string) MarketplaceOrder::TEMU_CANCELLED)
            ->whereNotNull('woocommerce_order_id')
            ->get()
            ->each(function (MarketplaceOrder $row) use ($client) {
                try {
                    $client->updateOrder($row->woocommerce_order_id, ['status' => 'cancelled']);
                    $client->addOrderNote($row->woocommerce_order_id, "The order was cancelled on Temu ({$row->external_order_id}).");
                } catch (WooCommerceRateLimitException $exception) {
                    throw $exception;
                } catch (WooCommerceException $exception) {
                    $row->update(['message' => __('Cancelled on Temu, but WooCommerce could not cancel it: :error', ['error' => $exception->getMessage()])]);

                    return;
                }

                $row->update(['status' => MarketplaceOrderStatus::Cancelled, 'message' => null]);
            });
    }

    /**
     * Create the waiting orders in WooCommerce.
     *
     * @param  array<string, array<string, mixed>>  $orders  Orders already read this run.
     */
    private function importPending(Shop $shop, TemuClient $temu, WooCommerceClient $client, BuildTemuWooCommerceOrder $build, CreateWooCommerceOrder $create, array $orders): void
    {
        $rows = $shop->marketplaceOrders()->forMarketplace(Marketplace::Temu)->where('status', MarketplaceOrderStatus::Pending->value)->oldest('id')->get();

        foreach ($rows as $row) {
            $order = $orders[$row->external_order_id]
                ?? $temu->call('bg.order.detail.v2.get', ['parentOrderSn' => $row->external_order_id]);

            $status = (int) ($order['parentOrderMap']['parentOrderStatus'] ?? 0);
            $row->update(['marketplace_status' => (string) $status]);

            match (true) {
                $status === MarketplaceOrder::TEMU_PENDING => null,
                $status === MarketplaceOrder::TEMU_CANCELLED => $row->update(['status' => MarketplaceOrderStatus::Cancelled, 'message' => null]),
                $status === MarketplaceOrder::TEMU_UNSHIPPED => $this->import($shop, $row, $order, $temu, $client, $build, $create),
                default => $row->update([
                    'status' => MarketplaceOrderStatus::Failed,
                    'message' => __('Temu shows the order as :status, so it was not imported. Enter it in WooCommerce by hand if it is missing.', ['status' => MarketplaceOrder::describeTemuStatus($status)]),
                ]),
            };
        }
    }

    /**
     * Create one order in WooCommerce.
     *
     * @param  array<string, mixed>  $order
     */
    private function import(Shop $shop, MarketplaceOrder $row, array $order, TemuClient $temu, WooCommerceClient $client, BuildTemuWooCommerceOrder $build, CreateWooCommerceOrder $create): void
    {
        try {
            $address = $temu->call('bg.order.shippinginfo.v2.get', ['parentOrderSn' => $row->external_order_id]);
            $payload = $build->handle($shop, $order, $address);
        } catch (TemuException $exception) {
            // The order stays waiting and is tried again on the next check.
            throw $exception;
        } catch (RuntimeException $exception) {
            $row->update(['status' => MarketplaceOrderStatus::Failed, 'message' => $exception->getMessage()]);

            return;
        }

        $create->handle($row, $client, $payload, $build->note($order, $payload));
    }

    /**
     * Read one of Temu's Unix timestamps.
     */
    private function time(mixed $timestamp): ?CarbonImmutable
    {
        return is_numeric($timestamp) && (int) $timestamp > 0
            ? CarbonImmutable::createFromTimestamp((int) $timestamp)
            : null;
    }
}
