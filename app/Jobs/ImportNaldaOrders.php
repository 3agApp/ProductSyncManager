<?php

namespace App\Jobs;

use App\Actions\Nalda\BuildNaldaWooCommerceOrder;
use App\Actions\Orders\AdoptWooCommerceOrders;
use App\Actions\Orders\CreateWooCommerceOrder;
use App\Enums\Marketplace;
use App\Enums\MarketplaceOrderStatus;
use App\Models\MarketplaceOrder;
use App\Models\NaldaConnection;
use App\Models\Shop;
use App\Services\Nalda\NaldaApiException;
use App\Services\Nalda\NaldaClient;
use App\Services\WooCommerce\Exceptions\WooCommerceException;
use App\Services\WooCommerce\Exceptions\WooCommerceRateLimitException;
use App\Services\WooCommerce\WooCommerceClient;
use App\Support\MarketplaceOrderMeta as Meta;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/**
 * Bring a shop's new Nalda orders into WooCommerce and follow their payout.
 *
 * Nalda's order list is read over the last weeks on every check: a payout
 * arrives a week or two after the order, so the window has to reach back
 * that far. New orders are created in WooCommerce once; known ones have
 * their payout state followed, and the WooCommerce order is marked paid when
 * Nalda pays it out, or cancelled when Nalda cancels it.
 *
 * The first check records the orders the store already has, which the old
 * Nalda plugin imported, before anything is created.
 */
class ImportNaldaOrders implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Days of orders read on every check.
     */
    private const int WINDOW_DAYS = 45;

    /**
     * Days of existing store orders recorded on the first check.
     */
    private const int ADOPT_DAYS = 180;

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

    public function handle(BuildNaldaWooCommerceOrder $build, CreateWooCommerceOrder $create, AdoptWooCommerceOrders $adopt): void
    {
        $shop = Shop::find($this->shopId);
        $connection = $shop?->naldaConnection;

        if ($shop === null || $connection === null || ! self::canImport($connection)) {
            return;
        }

        $nalda = new NaldaClient($connection);
        $client = new WooCommerceClient($shop->wooCommerceConnection);
        $startedAt = now();

        try {
            if ($connection->orders_checked_at === null) {
                $adopt->handle($shop, now()->subDays(self::ADOPT_DAYS));
            }

            $orders = $this->orders($nalda->orderItems(now()->subDays(self::WINDOW_DAYS), now()->addDay()));

            foreach ($orders as $id => $items) {
                $this->follow($shop, $client, (string) $id, $items);
            }

            $this->importPending($shop, $nalda, $client, $build, $create, $orders);
        } catch (NaldaApiException $exception) {
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
     * Determine if a shop is set up to import Nalda orders.
     */
    public static function canImport(NaldaConnection $connection): bool
    {
        return $connection->import_orders
            && $connection->hasUsableApiKey()
            && $connection->shop->wooCommerceConnection?->hasUsableCredentials() === true;
    }

    /**
     * Group Nalda's order rows by order.
     *
     * Nalda's order ids are numbers, so PHP keys the groups by integer.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function orders(array $rows): array
    {
        $orders = [];

        foreach ($rows as $row) {
            if (is_numeric($row['orderId'] ?? null)) {
                $orders[(int) $row['orderId']][] = $row;
            }
        }

        return $orders;
    }

    /**
     * Record a new order, or follow a known one's payout and cancellation.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function follow(Shop $shop, WooCommerceClient $client, string $id, array $items): void
    {
        $first = $items[0];
        $state = BuildNaldaWooCommerceOrder::state($first);
        $payout = strtoupper((string) ($first['payoutStatus'] ?? ''));
        $row = $shop->marketplaceOrders()->forMarketplace(Marketplace::Nalda)->where('external_order_id', $id)->first();

        if ($row === null) {
            $shop->marketplaceOrders()->create([
                'marketplace' => Marketplace::Nalda,
                'external_order_id' => $id,
                'marketplace_status' => $state,
                'status' => MarketplaceOrderStatus::Pending,
                'payout_status' => $payout,
                'gtins' => self::gtins($items),
                'ordered_at' => is_string($first['createdAt'] ?? null) ? CarbonImmutable::parse($first['createdAt']) : null,
            ]);

            return;
        }

        $paidOut = $payout === 'PAID_OUT' && $row->payout_status !== 'PAID_OUT';
        $cancelled = $state === 'CANCELLED' && $row->marketplace_status !== 'CANCELLED';

        $row->update(['marketplace_status' => $state, 'payout_status' => $payout]);

        if ($row->status === MarketplaceOrderStatus::Imported && $row->woocommerce_order_id !== null && ($paidOut || $cancelled)) {
            $this->update($client, $row, $paidOut, $cancelled);
        } elseif ($cancelled && in_array($row->status, [MarketplaceOrderStatus::Pending, MarketplaceOrderStatus::Failed], true)) {
            $row->update(['status' => MarketplaceOrderStatus::Cancelled, 'message' => null]);
        }
    }

    /**
     * The GTINs of an order's rows.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, string>
     */
    private static function gtins(array $items): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (array $item) => trim((string) ($item['gtin'] ?? '')),
            $items,
        ))));
    }

    /**
     * Mark an imported order paid, or cancel it, in WooCommerce.
     *
     * A change WooCommerce refuses is noted on the row; the payout state is
     * then read again on the next check, which tries once more.
     */
    private function update(WooCommerceClient $client, MarketplaceOrder $row, bool $paidOut, bool $cancelled): void
    {
        $data = ['meta_data' => Meta::list([Meta::PAYOUT_STATUS => $row->payout_status])];

        if ($cancelled) {
            $data['status'] = 'cancelled';
        }

        if ($paidOut) {
            $data['set_paid'] = true;
        }

        try {
            $client->updateOrder((int) $row->woocommerce_order_id, $data);
            $client->addOrderNote((int) $row->woocommerce_order_id, $cancelled
                ? "The order was cancelled on Nalda ({$row->external_order_id})."
                : "Nalda paid this order out ({$row->external_order_id}).");
        } catch (WooCommerceRateLimitException $exception) {
            throw $exception;
        } catch (WooCommerceException $exception) {
            $row->update([
                'message' => __('Nalda changed the order, but WooCommerce could not be updated: :error', ['error' => $exception->getMessage()]),
                // Forget the change, so the next check sees it again.
                'payout_status' => $paidOut ? null : $row->payout_status,
                'marketplace_status' => $cancelled ? '' : $row->marketplace_status,
            ]);

            return;
        }

        if ($cancelled) {
            $row->update(['status' => MarketplaceOrderStatus::Cancelled, 'message' => null]);
        }
    }

    /**
     * Create the waiting orders in WooCommerce.
     *
     * @param  array<int, array<int, array<string, mixed>>>  $orders  Orders already read this run.
     */
    private function importPending(Shop $shop, NaldaClient $nalda, WooCommerceClient $client, BuildNaldaWooCommerceOrder $build, CreateWooCommerceOrder $create, array $orders): void
    {
        $rows = $shop->marketplaceOrders()->forMarketplace(Marketplace::Nalda)
            ->where('status', MarketplaceOrderStatus::Pending->value)
            ->oldest('id')
            ->get();

        foreach ($rows as $row) {
            $items = $orders[(int) $row->external_order_id] ?? $this->itemsAround($nalda, $row);

            try {
                $payload = $build->handle($shop, $items, $nalda->order((int) $row->external_order_id));
            } catch (NaldaApiException $exception) {
                // The order stays waiting and is tried again on the next check.
                throw $exception;
            } catch (RuntimeException $exception) {
                $row->update(['status' => MarketplaceOrderStatus::Failed, 'message' => $exception->getMessage()]);

                continue;
            }

            $create->handle($row, $client, $payload, $build->note($payload));
        }
    }

    /**
     * Read an order's rows when it is older than this run's window.
     *
     * @return array<int, array<string, mixed>>
     */
    private function itemsAround(NaldaClient $nalda, MarketplaceOrder $row): array
    {
        $day = $row->ordered_at ?? now();

        return $this->orders($nalda->orderItems($day->subDay(), $day->addDay()))[(int) $row->external_order_id] ?? [];
    }
}
