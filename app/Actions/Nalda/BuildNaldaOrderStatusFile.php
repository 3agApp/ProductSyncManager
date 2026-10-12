<?php

namespace App\Actions\Nalda;

use App\Enums\Marketplace;
use App\Models\MarketplaceOrder;
use App\Models\NaldaConnection;
use RuntimeException;

/**
 * Writes the order status file Nalda reads from its SFTP server.
 *
 * One row per article of every Nalda order from the last weeks that has a
 * delivery state Nalda accepts: orderId, gtin, state, expectedDeliveryDate
 * (dd.mm.yy) and trackingCode. Nalda takes the whole file each time, so
 * orders keep being sent after they are delivered, as the old plugin did.
 */
class BuildNaldaOrderStatusFile
{
    /**
     * The delivery states Nalda accepts in the file.
     */
    public const array STATES = [
        'IN_PREPARATION',
        'READY_TO_COLLECT',
        'IN_DELIVERY',
        'DELIVERED',
        'CANCELLED',
        'RETURNED',
        'DISPUTE',
    ];

    /**
     * Days of orders the file covers.
     */
    private const int DAYS = 60;

    /**
     * Build the file.
     *
     * @return array{content: string, orders: int, rows: int}
     */
    public function handle(NaldaConnection $connection): array
    {
        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            throw new RuntimeException('Could not open a temporary stream for the Nalda status file.');
        }

        fputcsv($stream, ['orderId', 'gtin', 'state', 'expectedDeliveryDate', 'trackingCode'], escape: '');
        $orders = 0;
        $rows = 0;

        MarketplaceOrder::query()
            ->where('shop_id', $connection->shop_id)
            ->forMarketplace(Marketplace::Nalda)
            ->whereNotNull('woocommerce_order_id')
            ->whereIn('delivery_status', self::STATES)
            ->where(fn ($query) => $query
                ->where('ordered_at', '>=', now()->subDays(self::DAYS))
                ->orWhere(fn ($query) => $query->whereNull('ordered_at')->where('created_at', '>=', now()->subDays(self::DAYS))))
            ->orderBy('id')
            ->each(function (MarketplaceOrder $order) use ($stream, $connection, &$orders, &$rows) {
                $gtins = $order->gtins ?? [];

                if ($gtins === []) {
                    return;
                }

                $orders++;

                foreach ($gtins as $gtin) {
                    fputcsv($stream, [
                        $order->external_order_id,
                        $gtin,
                        $order->delivery_status,
                        $this->expected($order, $connection),
                        (string) $order->tracking_code,
                    ], escape: '');
                    $rows++;
                }
            });

        rewind($stream);
        $content = (string) stream_get_contents($stream);
        fclose($stream);

        return ['content' => $content, 'orders' => $orders, 'rows' => $rows];
    }

    /**
     * The expected delivery date, as Nalda writes it.
     *
     * Without a date on the order, it is the order date plus the shop's
     * delivery time.
     */
    private function expected(MarketplaceOrder $order, NaldaConnection $connection): string
    {
        $date = $order->expected_delivery_on
            ?? ($order->ordered_at ?? $order->created_at)?->addDays($connection->delivery_time_days);

        return $date?->format('d.m.y') ?? '';
    }
}
