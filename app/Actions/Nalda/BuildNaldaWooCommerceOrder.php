<?php

namespace App\Actions\Nalda;

use App\Models\Product;
use App\Models\Shop;
use App\Support\MarketplaceOrderMeta as Meta;
use App\Support\WooCommerceAmount;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Turns a Nalda order into what WooCommerce's order API takes.
 *
 * Nalda is the customer: it is billed for the order and pays the shop what
 * the buyer paid less its commission, on the articles and on the shipping
 * fee alike. The WooCommerce total is that payout, VAT included, so it
 * matches what arrives from Nalda. The buyer is the shipping address, and
 * their email goes into the marketplace meta, where the WooCommerce plugin
 * hands it to Planzer and keeps WooCommerce from writing to them.
 */
class BuildNaldaWooCommerceOrder
{
    /**
     * Nalda Marketplace AG, who every order is billed to.
     */
    private const array BILLING = [
        'first_name' => 'Nalda',
        'last_name' => 'Marketplace',
        'company' => 'Nalda Marketplace AG',
        'address_1' => 'Grabenstrasse 15a',
        'city' => 'Baar',
        'state' => 'ZG',
        'postcode' => '6340',
        'country' => 'CH',
        'email' => 'orders@nalda.com',
    ];

    private const string VAT_NUMBER = 'CHE-353.496.457 MWST';

    /**
     * WooCommerce's status for each of Nalda's delivery states.
     */
    public const array STATUSES = [
        'IN_PREPARATION' => 'processing',
        'IN_DELIVERY' => 'processing',
        'READY_TO_COLLECT' => 'processing',
        'DELIVERED' => 'completed',
        'COLLECTED' => 'completed',
        'CANCELLED' => 'cancelled',
        'RETURNED' => 'refunded',
        'DISPUTE' => 'on-hold',
        'UNDELIVERABLE' => 'failed',
        'NOT_PICKED_UP' => 'failed',
    ];

    /**
     * Build the request for WooCommerce's create order call.
     *
     * @param  array<int, array<string, mixed>>  $items  The order's rows from Nalda's order items.
     * @param  array<string, mixed>  $details  The order from Nalda's single-order call.
     * @return array<string, mixed>
     *
     * @throws RuntimeException when the order cannot be built.
     */
    public function handle(Shop $shop, array $items, array $details): array
    {
        if ($items === []) {
            throw new RuntimeException(__('The Nalda order has no items to import.'));
        }

        $first = $items[0];
        $state = self::state($first);
        $fee = (float) ($details['fee'] ?? 0);
        $feeCommission = (float) ($details['commission'] ?? 0);
        $lines = array_map(fn (array $item) => $this->line($shop, $item), $items);
        $paid = strtoupper((string) ($first['payoutStatus'] ?? '')) === 'PAID_OUT';
        $email = trim((string) ($first['email'] ?? ''));
        $planned = $this->date($first['deliveryDatePlanned'] ?? null);

        return [
            'status' => self::STATUSES[$state] ?? 'processing',
            'set_paid' => $paid,
            'payment_method' => 'nalda',
            'payment_method_title' => 'Nalda Marketplace',
            'transaction_id' => (string) $first['orderId'],
            'created_via' => 'nalda',
            'customer_id' => 0,
            'currency' => strtoupper((string) ($first['currency'] ?? $shop->currency)),
            'billing' => self::BILLING,
            'shipping' => [
                'first_name' => trim((string) ($first['firstName'] ?? '')),
                'last_name' => trim((string) ($first['lastName'] ?? '')),
                'address_1' => trim((string) ($first['street1'] ?? '')),
                'postcode' => trim((string) ($first['postalCode'] ?? '')),
                'city' => trim((string) ($first['city'] ?? '')),
                'country' => strtoupper(trim((string) ($first['country'] ?? 'CH'))) ?: 'CH',
            ],
            'line_items' => array_column($lines, 'item'),
            'shipping_lines' => $fee > 0 ? [[
                'method_id' => 'nalda',
                'method_title' => 'Nalda',
                'total' => WooCommerceAmount::net($shop, $fee - $feeCommission),
            ]] : [],
            'meta_data' => Meta::list([
                Meta::MARKETPLACE => 'nalda',
                Meta::ORDER_ID => (string) $first['orderId'],
                Meta::END_CUSTOMER_EMAIL => filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null,
                Meta::DELIVERY_STATUS => $state,
                Meta::EXPECTED_DELIVERY_DATE => $planned?->format('Y-m-d'),
                Meta::CUSTOMER_TOTAL => $this->amount(array_sum(array_column($lines, 'paid')) + $fee),
                Meta::COMMISSION => $this->amount(array_sum(array_column($lines, 'commission')) + $feeCommission),
                Meta::SHIPPING_PAYOUT => $fee > 0 ? $this->amount($fee - $feeCommission) : null,
                Meta::PAYOUT_STATUS => strtoupper((string) ($first['payoutStatus'] ?? '')),
                Meta::PLACED_AT => $this->date($first['createdAt'] ?? null)?->toIso8601String(),
                '_billing_vat_number' => self::VAT_NUMBER,
            ]),
        ];
    }

    /**
     * Nalda's delivery state for an order row, upper case.
     *
     * The API documents the field as `deliveryStatus`, but answers it as a
     * lower-case `status`; both are read.
     *
     * @param  array<string, mixed>  $item
     */
    public static function state(array $item): string
    {
        return strtoupper((string) ($item['status'] ?? $item['deliveryStatus'] ?? 'IN_PREPARATION'));
    }

    /**
     * Write the private note that goes on the WooCommerce order.
     *
     * @param  array<string, mixed>  $payload  The request handle() built.
     */
    public function note(array $payload): string
    {
        $id = Meta::read($payload, Meta::ORDER_ID);
        $note = "Imported from Nalda order {$id}. The total is Nalda's payout: what the buyer paid less Nalda's commission, VAT included.";

        $unmatched = array_filter($payload['line_items'], fn (array $item) => ! isset($item['product_id']));

        if ($unmatched !== []) {
            $note .= ' Not found in the catalog by GTIN, so stock was not changed: '
                .implode(', ', array_column($unmatched, 'name')).'.';
        }

        return $note;
    }

    /**
     * Build one line, priced at what Nalda pays for it.
     *
     * @param  array<string, mixed>  $item
     * @return array{item: array<string, mixed>, paid: float, commission: float}
     */
    private function line(Shop $shop, array $item): array
    {
        $quantity = max(1, (int) ($item['quantity'] ?? 1));
        $price = (float) ($item['price'] ?? 0);
        $commission = (float) ($item['commission'] ?? 0);
        $paid = $price * $quantity;
        $total = WooCommerceAmount::net($shop, $paid - $commission);
        $gtin = trim((string) ($item['gtin'] ?? ''));

        $line = [
            'quantity' => $quantity,
            'subtotal' => $total,
            'total' => $total,
            'meta_data' => Meta::list([
                Meta::ITEM_GTIN => $gtin,
                Meta::ITEM_CUSTOMER_PRICE => $this->amount($price),
                Meta::ITEM_COMMISSION => $this->amount($commission),
            ]),
        ];

        $product = $this->product($shop, $gtin);

        if ($product?->remote_id !== null) {
            $line['product_id'] = $product->remote_id;
        } else {
            $line['name'] = trim((string) ($item['title'] ?? '')) ?: ($gtin !== '' ? "GTIN {$gtin}" : 'Nalda item');
        }

        return ['item' => $line, 'paid' => $paid, 'commission' => $commission];
    }

    /**
     * Find the catalog product a GTIN belongs to.
     *
     * GTINs are compared without leading zeros, since an EAN-13 and the same
     * code written as a GTIN-14 differ only in them.
     */
    private function product(Shop $shop, string $gtin): ?Product
    {
        $bare = ltrim($gtin, '0');

        if ($bare === '') {
            return null;
        }

        $candidates = array_unique([$gtin, $bare, str_pad($bare, 13, '0', STR_PAD_LEFT), str_pad($bare, 14, '0', STR_PAD_LEFT)]);

        return $shop->products()->whereIn('gtin', $candidates)->whereNotNull('remote_id')->first();
    }

    /**
     * Format an amount with two decimals.
     */
    private function amount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /**
     * Read one of Nalda's ISO dates.
     */
    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $date = rescue(fn () => CarbonImmutable::parse($value), null, report: false);

        return $date !== null && $date->year > 1970 ? $date : null;
    }
}
