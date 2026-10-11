<?php

namespace App\Jobs;

use App\Actions\Temu\BuildWooCommerceOrder;
use App\Enums\TemuOrderStatus;
use App\Models\Shop;
use App\Models\TemuConnection;
use App\Models\TemuOrder;
use App\Services\Temu\TemuClient;
use App\Services\Temu\TemuException;
use App\Services\WooCommerce\Exceptions\WooCommerceException;
use App\Services\WooCommerce\Exceptions\WooCommerceRateLimitException;
use App\Services\WooCommerce\Exceptions\WooCommerceRequestException;
use App\Services\WooCommerce\WooCommerceClient;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use RuntimeException;

/**
 * Bring a shop's new Temu orders into WooCommerce.
 *
 * WooCommerce fulfils the orders and owns the stock: creating the order
 * there lowers the stock, which reaches Temu through the usual product sync.
 * Orders updated on Temu since the last check are read, unshipped ones are
 * created in WooCommerce once, and imported ones Temu cancels are cancelled
 * there too, which puts their stock back.
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

    public function handle(BuildWooCommerceOrder $build): void
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
            $this->importPending($shop, $temu, $client, $build, $orders);
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
        $row = $shop->temuOrders()->where('parent_order_sn', $parent['parentOrderSn'])->first();

        if ($row === null) {
            if (! in_array($status, [TemuOrder::TEMU_PENDING, TemuOrder::TEMU_UNSHIPPED], true)) {
                return;
            }

            $shop->temuOrders()->create([
                'parent_order_sn' => $parent['parentOrderSn'],
                'temu_status' => $status,
                'status' => TemuOrderStatus::Pending,
                'ordered_at' => $this->time($parent['parentOrderTime'] ?? null),
                'ship_by_at' => $this->time($parent['expectShipLatestTime'] ?? null),
            ]);

            return;
        }

        $row->temu_status = $status;
        $row->ship_by_at = $this->time($parent['expectShipLatestTime'] ?? null) ?? $row->ship_by_at;

        if ($status === TemuOrder::TEMU_CANCELLED && in_array($row->status, [TemuOrderStatus::Pending, TemuOrderStatus::Failed], true)) {
            $row->status = TemuOrderStatus::Cancelled;
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
        $shop->temuOrders()
            ->where('status', TemuOrderStatus::Imported->value)
            ->where('temu_status', TemuOrder::TEMU_CANCELLED)
            ->whereNotNull('woocommerce_order_id')
            ->get()
            ->each(function (TemuOrder $row) use ($client) {
                try {
                    $client->updateOrder($row->woocommerce_order_id, ['status' => 'cancelled']);
                    $client->addOrderNote($row->woocommerce_order_id, "The order was cancelled on Temu ({$row->parent_order_sn}).");
                } catch (WooCommerceRateLimitException $exception) {
                    throw $exception;
                } catch (WooCommerceException $exception) {
                    $row->update(['message' => __('Cancelled on Temu, but WooCommerce could not cancel it: :error', ['error' => $exception->getMessage()])]);

                    return;
                }

                $row->update(['status' => TemuOrderStatus::Cancelled, 'message' => null]);
            });
    }

    /**
     * Create the waiting orders in WooCommerce.
     *
     * @param  array<string, array<string, mixed>>  $orders  Orders already read this run.
     */
    private function importPending(Shop $shop, TemuClient $temu, WooCommerceClient $client, BuildWooCommerceOrder $build, array $orders): void
    {
        $rows = $shop->temuOrders()->where('status', TemuOrderStatus::Pending->value)->oldest('id')->get();

        foreach ($rows as $row) {
            $order = $orders[$row->parent_order_sn]
                ?? $temu->call('bg.order.detail.v2.get', ['parentOrderSn' => $row->parent_order_sn]);

            $status = (int) ($order['parentOrderMap']['parentOrderStatus'] ?? 0);
            $row->update(['temu_status' => $status]);

            match (true) {
                $status === TemuOrder::TEMU_PENDING => null,
                $status === TemuOrder::TEMU_CANCELLED => $row->update(['status' => TemuOrderStatus::Cancelled, 'message' => null]),
                $status === TemuOrder::TEMU_UNSHIPPED => $this->import($shop, $row, $order, $temu, $client, $build),
                default => $row->update([
                    'status' => TemuOrderStatus::Failed,
                    'message' => __('Temu shows the order as :status, so it was not imported. Enter it in WooCommerce by hand if it is missing.', ['status' => TemuOrder::describeTemuStatus($status)]),
                ]),
            };
        }
    }

    /**
     * Create one order in WooCommerce.
     *
     * The row is claimed as creating before the request, so it is sent at
     * most once. A request cut off before WooCommerce answered may still have
     * created the order, so the row stays creating for someone to check
     * rather than being sent again.
     *
     * @param  array<string, mixed>  $order
     */
    private function import(Shop $shop, TemuOrder $row, array $order, TemuClient $temu, WooCommerceClient $client, BuildWooCommerceOrder $build): void
    {
        try {
            $address = $temu->call('bg.order.shippinginfo.v2.get', ['parentOrderSn' => $row->parent_order_sn]);
            $payload = $build->handle($shop, $order, $address);
        } catch (TemuException $exception) {
            // The order stays waiting and is tried again on the next check.
            throw $exception;
        } catch (RuntimeException $exception) {
            $row->update(['status' => TemuOrderStatus::Failed, 'message' => $exception->getMessage()]);

            return;
        }

        $claimed = TemuOrder::whereKey($row->id)
            ->where('status', TemuOrderStatus::Pending->value)
            ->update(['status' => TemuOrderStatus::Creating->value, 'message' => null]);

        if ($claimed === 0) {
            return;
        }

        try {
            $created = $client->createOrder($payload);
        } catch (WooCommerceRateLimitException $exception) {
            $row->update(['status' => TemuOrderStatus::Pending]);

            throw $exception;
        } catch (WooCommerceRequestException $exception) {
            $row->update($exception->getPrevious() instanceof ConnectionException
                ? ['status' => TemuOrderStatus::Creating, 'message' => __('WooCommerce did not answer. Check whether the order was created before importing it again.')]
                : ['status' => TemuOrderStatus::Failed, 'message' => $exception->getMessage()]);

            return;
        } catch (WooCommerceException $exception) {
            $row->update(['status' => TemuOrderStatus::Failed, 'message' => $exception->userMessage()]);

            return;
        }

        $row->update([
            'status' => TemuOrderStatus::Imported,
            'woocommerce_order_id' => $created['id'],
            'imported_at' => now(),
            'message' => null,
        ]);

        try {
            $client->addOrderNote($created['id'], $build->note($order, $payload));
        } catch (WooCommerceException $exception) {
            report($exception);
        }
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
