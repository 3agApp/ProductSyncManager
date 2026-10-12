<?php

namespace App\Actions\Temu;

use App\Enums\Marketplace;
use App\Enums\MarketplaceOrderStatus;
use App\Models\MarketplaceOrder;
use App\Models\Shop;
use App\Models\TemuConnection;
use App\Services\Temu\TemuClient;
use App\Services\Temu\TemuException;
use App\Services\WooCommerce\Exceptions\WooCommerceException;
use App\Services\WooCommerce\WooCommerceClient;

/**
 * Tells Temu that its orders have shipped, with Planzer's parcel number.
 *
 * The Product Sync Manager plugin copies Planzer's parcel number onto each
 * WooCommerce order, and it has already been read back onto the order rows.
 * Every imported order Temu still waits on that now has a number is
 * confirmed as one Planzer parcel. Temu refusing an order (an unknown
 * number, a cancellation under review) is kept on the row for someone to
 * look at and is never sent again by itself; a passing fault is thrown so
 * the job tries again.
 */
class ConfirmTemuShipments
{
    /**
     * The carrier every order ships with, as Temu names it.
     */
    private const string CARRIER = 'Planzer';

    /**
     * Temu's sendType for a whole order in one parcel.
     */
    private const int SINGLE_PACKAGE = 0;

    /**
     * Confirm every order that is ready to.
     *
     * @return int How many orders were confirmed.
     *
     * @throws TemuException when Temu cannot be reached or asks to slow down.
     */
    public function handle(Shop $shop, TemuClient $temu, WooCommerceClient $woo): int
    {
        $confirmed = 0;

        foreach ($this->ready($shop) as $row) {
            $confirmed += $this->confirm($shop->temuConnection, $row, $temu, $woo) ? 1 : 0;
        }

        return $confirmed;
    }

    /**
     * The imported orders Temu waits on that have a parcel number.
     *
     * @return iterable<MarketplaceOrder>
     */
    private function ready(Shop $shop): iterable
    {
        return $shop->marketplaceOrders()
            ->forMarketplace(Marketplace::Temu)
            ->where('status', MarketplaceOrderStatus::Imported->value)
            ->where('marketplace_status', (string) MarketplaceOrder::TEMU_UNSHIPPED)
            ->whereNotNull('tracking_code')
            ->whereNull('shipment_confirmed_at')
            ->whereNull('shipment_message')
            ->oldest('id')
            ->get();
    }

    /**
     * Confirm one order.
     *
     * @throws TemuException when Temu cannot be reached or asks to slow down.
     */
    private function confirm(TemuConnection $connection, MarketplaceOrder $row, TemuClient $temu, WooCommerceClient $woo): bool
    {
        try {
            $order = $temu->call('bg.order.detail.v2.get', ['parentOrderSn' => $row->external_order_id]);
            $status = (int) ($order['parentOrderMap']['parentOrderStatus'] ?? 0);

            if ($status !== MarketplaceOrder::TEMU_UNSHIPPED) {
                $row->update(['marketplace_status' => (string) $status]);

                return false;
            }

            $carrier = $this->carrier($connection, $temu, (int) ($order['parentOrderMap']['regionId'] ?? 0));

            if ($carrier === null) {
                $row->update(['shipment_message' => __('Temu does not list :carrier as a carrier for this order\'s country. Confirm the shipment in Temu Seller Center.', ['carrier' => self::CARRIER])]);

                return false;
            }

            $temu->call('bg.logistics.shipment.v2.confirm', [
                'sendType' => self::SINGLE_PACKAGE,
                'sendRequestList' => [[
                    'orderSendInfoList' => $this->lines($row, $order),
                    'carrierId' => $carrier,
                    'trackingNumber' => (string) $row->tracking_code,
                ]],
            ]);
        } catch (TemuException $exception) {
            if ($exception->isRetryable() || $exception->isConnectionProblem()) {
                throw $exception;
            }

            $row->update(['shipment_message' => __('Temu refused the shipment: :error', ['error' => $exception->userMessage()])]);

            return false;
        }

        $row->update([
            'marketplace_status' => (string) MarketplaceOrder::TEMU_SHIPPED,
            'shipment_confirmed_at' => now(),
            'shipment_message' => null,
        ]);

        $this->note($row, $woo);

        return true;
    }

    /**
     * The order's lines still to ship, as Temu's confirmation takes them.
     *
     * @param  array<string, mixed>  $order
     * @return array<int, array<string, mixed>>
     */
    private function lines(MarketplaceOrder $row, array $order): array
    {
        $lines = [];

        foreach (is_array($order['orderList'] ?? null) ? $order['orderList'] : [] as $line) {
            if (! is_array($line) || (int) ($line['orderStatus'] ?? 0) !== MarketplaceOrder::TEMU_UNSHIPPED || (int) ($line['quantity'] ?? 0) <= 0) {
                continue;
            }

            $lines[] = [
                'parentOrderSn' => $row->external_order_id,
                'orderSn' => (string) $line['orderSn'],
                'goodsId' => (int) $line['goodsId'],
                'skuId' => (int) $line['skuId'],
                'quantity' => (int) $line['quantity'],
            ];
        }

        return $lines;
    }

    /**
     * Temu's id for Planzer, looked up once and kept on the connection.
     */
    private function carrier(TemuConnection $connection, TemuClient $temu, int $regionId): ?int
    {
        if ($connection->carrier_id !== null) {
            return $connection->carrier_id;
        }

        $carriers = $temu->call('bg.logistics.companies.get', ['regionId' => $regionId]);

        foreach ($carriers as $carrier) {
            if (is_array($carrier) && strcasecmp(trim((string) ($carrier['logisticsServiceProviderName'] ?? '')), self::CARRIER) === 0) {
                $connection->update(['carrier_id' => (int) $carrier['logisticsServiceProviderId']]);

                return $connection->carrier_id;
            }
        }

        return null;
    }

    /**
     * Note the confirmation on the WooCommerce order.
     *
     * Temu already has the shipment, so a note that cannot be added is only
     * reported.
     */
    private function note(MarketplaceOrder $row, WooCommerceClient $woo): void
    {
        if ($row->woocommerce_order_id === null) {
            return;
        }

        try {
            $woo->addOrderNote($row->woocommerce_order_id, "Shipment confirmed on Temu: {$row->tracking_code} with ".self::CARRIER.'.');
        } catch (WooCommerceException $exception) {
            report($exception);
        }
    }
}
