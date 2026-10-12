<?php

namespace App\Actions\Nalda;

use App\Enums\Marketplace;
use App\Models\Shop;
use App\Services\Nalda\NaldaApiException;
use App\Services\Nalda\NaldaClient;
use App\Services\WooCommerce\Exceptions\WooCommerceException;
use App\Services\WooCommerce\WooCommerceClient;
use App\Support\MarketplaceOrderMeta as Meta;
use App\Support\WooCommerceAmount;
use Carbon\CarbonInterface;

/**
 * Puts the Nalda orders the old "Nalda Marketplace Sync" plugin imported at
 * what Nalda paid for them.
 *
 * That plugin added VAT on top of each line's payout and left out the
 * shipping fee, so its orders do not add up to the payout. Each one is
 * priced again the way this app imports Nalda orders: every line at what the
 * buyer paid less Nalda's commission, plus a "Nalda" shipping line at the
 * fee less its commission, VAT included. Orders that are cancelled,
 * refunded, or already right are left alone.
 */
class CorrectLegacyNaldaTotals
{
    /**
     * The most pages of a hundred orders read.
     */
    private const int MAX_PAGES = 100;

    /**
     * WooCommerce statuses whose amounts are not the payout.
     */
    private const array SKIPPED_STATUSES = ['cancelled', 'refunded', 'failed', 'trash'];

    /**
     * Check, and with $apply correct, every Nalda order created since a date.
     *
     * @return array<int, array{woocommerce_id: int, nalda_id: string, from: string, to: string|null, result: string}>
     *
     * @throws WooCommerceException
     * @throws NaldaApiException
     */
    public function handle(Shop $shop, CarbonInterface $since, bool $apply): array
    {
        $woo = new WooCommerceClient($shop->wooCommerceConnection);
        $nalda = new NaldaClient($shop->naldaConnection);
        $results = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $result = $woo->orderPage($page, ['after' => $since->toIso8601String(), 'status' => 'any']);

            foreach ($result['orders'] as $order) {
                $id = Meta::read($order, Meta::ORDER_ID) ?? Meta::read($order, '_nalda_order_id');

                if ($id !== null && $this->isNalda($order)) {
                    $results[] = $this->correct($shop, $woo, $nalda, $order, $id, $apply);
                }
            }

            if ($result['orders'] === [] || ($result['pages'] !== null && $page >= $result['pages'])) {
                break;
            }
        }

        return $results;
    }

    /**
     * Check one order, and correct it when asked.
     *
     * @param  array<string, mixed>  $order
     * @return array{woocommerce_id: int, nalda_id: string, from: string, to: string|null, result: string}
     *
     * @throws WooCommerceException
     * @throws NaldaApiException
     */
    private function correct(Shop $shop, WooCommerceClient $woo, NaldaClient $nalda, array $order, string $id, bool $apply): array
    {
        $outcome = ['woocommerce_id' => (int) $order['id'], 'nalda_id' => $id, 'from' => (string) ($order['total'] ?? ''), 'to' => null];
        $skip = $this->skipReason($order);

        if ($skip !== null) {
            return [...$outcome, 'result' => $skip];
        }

        $details = $nalda->order((int) $id);

        if ((float) ($details['refund'] ?? 0) > 0) {
            return [...$outcome, 'result' => 'skipped: refunded by Nalda'];
        }

        $correction = $this->correction($shop, $order, $details);
        $outcome['to'] = $this->amount($correction['payout']);

        if (abs((float) $outcome['from'] - $correction['payout']) < 0.02) {
            return [...$outcome, 'result' => 'already right'];
        }

        if (! $apply) {
            return [...$outcome, 'result' => 'would correct'];
        }

        $saved = $woo->updateOrder($outcome['woocommerce_id'], $correction['payload']);
        $woo->addOrderNote($outcome['woocommerce_id'], "Totals corrected to Nalda's payout, VAT included: {$outcome['from']} → {$saved['total']}. The old Nalda plugin added VAT on top of the payout and left out the shipping fee.");

        $shop->marketplaceOrders()
            ->forMarketplace(Marketplace::Nalda)
            ->where('external_order_id', $id)
            ->update(['customer_total' => $correction['customer_total'], 'commission' => $correction['commission']]);

        return [...$outcome, 'to' => (string) $saved['total'], 'result' => 'corrected'];
    }

    /**
     * Why an order is left alone, or null to check it.
     *
     * @param  array<string, mixed>  $order
     */
    private function skipReason(array $order): ?string
    {
        if (in_array($order['status'] ?? '', self::SKIPPED_STATUSES, true)) {
            return 'skipped: '.$order['status'];
        }

        if (! empty($order['refunds'])) {
            return 'skipped: has refunds';
        }

        foreach ($this->lineItems($order) as $line) {
            if ($this->itemMeta($line, Meta::ITEM_CUSTOMER_PRICE, '_nalda_customer_price') === null) {
                return 'skipped: a line has no Nalda price';
            }
        }

        return $this->lineItems($order) === [] ? 'skipped: no lines' : null;
    }

    /**
     * The update that prices the order at Nalda's payout.
     *
     * @param  array<string, mixed>  $order
     * @param  array<string, mixed>  $details  The order from Nalda's single-order call.
     * @return array{payload: array<string, mixed>, payout: float, customer_total: string, commission: string}
     */
    private function correction(Shop $shop, array $order, array $details): array
    {
        $fee = (float) ($details['fee'] ?? 0);
        $feeCommission = (float) ($details['commission'] ?? 0);
        $paid = $fee;
        $commission = $feeCommission;
        $lines = [];

        foreach ($this->lineItems($order) as $line) {
            $quantity = max(1, (int) ($line['quantity'] ?? 1));
            $linePaid = (float) $this->itemMeta($line, Meta::ITEM_CUSTOMER_PRICE, '_nalda_customer_price') * $quantity;
            $lineCommission = (float) $this->itemMeta($line, Meta::ITEM_COMMISSION, '_nalda_commission');
            $total = WooCommerceAmount::net($shop, $linePaid - $lineCommission);

            $lines[] = ['id' => (int) $line['id'], 'subtotal' => $total, 'total' => $total];
            $paid += $linePaid;
            $commission += $lineCommission;
        }

        return [
            'payload' => [
                'line_items' => $lines,
                'shipping_lines' => $this->shippingLines($shop, $order, $fee - $feeCommission),
                'meta_data' => Meta::list([
                    Meta::CUSTOMER_TOTAL => $this->amount($paid),
                    Meta::COMMISSION => $this->amount($commission),
                    Meta::SHIPPING_PAYOUT => $fee > 0 ? $this->amount($fee - $feeCommission) : null,
                ]),
            ],
            'payout' => $paid - $commission,
            'customer_total' => $this->amount($paid),
            'commission' => $this->amount($commission),
        ];
    }

    /**
     * The order's shipping at the fee's payout: its first shipping line
     * repriced, or a new "Nalda" line.
     *
     * @param  array<string, mixed>  $order
     * @return array<int, array<string, mixed>>
     */
    private function shippingLines(Shop $shop, array $order, float $payout): array
    {
        $existing = is_array($order['shipping_lines'] ?? null) ? $order['shipping_lines'] : [];
        $total = WooCommerceAmount::net($shop, max(0, $payout));

        if ($existing !== [] && is_array($existing[0]) && isset($existing[0]['id'])) {
            return [['id' => (int) $existing[0]['id'], 'total' => $total]];
        }

        return $payout > 0 ? [['method_id' => 'nalda', 'method_title' => 'Nalda', 'total' => $total]] : [];
    }

    /**
     * Whether a WooCommerce order came from Nalda.
     *
     * @param  array<string, mixed>  $order
     */
    private function isNalda(array $order): bool
    {
        $marketplace = Meta::read($order, Meta::MARKETPLACE);

        return $marketplace === Marketplace::Nalda->value || ($marketplace === null && Meta::read($order, '_nalda_order_id') !== null);
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array<int, array<string, mixed>>
     */
    private function lineItems(array $order): array
    {
        return array_values(array_filter(is_array($order['line_items'] ?? null) ? $order['line_items'] : [], 'is_array'));
    }

    /**
     * Read a line's meta under its new key or the old plugin's.
     *
     * @param  array<string, mixed>  $line
     */
    private function itemMeta(array $line, string $key, string $legacy): ?string
    {
        $value = Meta::read($line, $key) ?? Meta::read($line, $legacy);

        return is_numeric($value) ? $value : null;
    }

    /**
     * Format an amount with two decimals.
     */
    private function amount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
