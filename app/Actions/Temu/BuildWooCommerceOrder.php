<?php

namespace App\Actions\Temu;

use App\Models\Product;
use App\Models\Shop;
use App\Models\TemuProduct;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Turns a Temu order into what WooCommerce's order API takes.
 *
 * Temu is the customer: it pays the shop its base price and owns the
 * relationship with the buyer. The order is billed to Temu without an email
 * address, so WooCommerce sends the buyer nothing, and shipped to the buyer.
 */
class BuildWooCommerceOrder
{
    /**
     * Temu's region ids, for the regions this app's EU gateway serves.
     */
    private const array REGIONS = [
        192 => 'CH',
    ];

    /**
     * Country names Temu writes in an address, for regions not in REGIONS.
     */
    private const array COUNTRIES = [
        'switzerland' => 'CH',
        'liechtenstein' => 'LI',
        'germany' => 'DE',
        'austria' => 'AT',
        'france' => 'FR',
        'italy' => 'IT',
    ];

    /**
     * Swiss cantons as WooCommerce codes them, by the names Temu writes.
     */
    private const array CANTONS = [
        'aargau' => 'AG', 'appenzell ausserrhoden' => 'AR', 'appenzell innerrhoden' => 'AI',
        'basel-landschaft' => 'BL', 'basel-stadt' => 'BS', 'bern' => 'BE', 'fribourg' => 'FR',
        'geneva' => 'GE', 'genève' => 'GE', 'glarus' => 'GL', 'graubünden' => 'GR', 'jura' => 'JU',
        'lucerne' => 'LU', 'luzern' => 'LU', 'neuchâtel' => 'NE', 'nidwalden' => 'NW',
        'obwalden' => 'OW', 'schaffhausen' => 'SH', 'schwyz' => 'SZ', 'solothurn' => 'SO',
        'st. gallen' => 'SG', 'st gallen' => 'SG', 'thurgau' => 'TG', 'ticino' => 'TI', 'uri' => 'UR',
        'valais' => 'VS', 'wallis' => 'VS', 'vaud' => 'VD', 'zug' => 'ZG', 'zurich' => 'ZH', 'zürich' => 'ZH',
    ];

    /**
     * Build the request for WooCommerce's create order call.
     *
     * @param  array<string, mixed>  $order  Temu's order: parentOrderMap and orderList.
     * @param  array<string, mixed>  $address  Temu's shipping info for the order.
     * @return array<string, mixed>
     *
     * @throws RuntimeException when the order cannot be built.
     */
    public function handle(Shop $shop, array $order, array $address): array
    {
        $parent = is_array($order['parentOrderMap'] ?? null) ? $order['parentOrderMap'] : [];
        $sn = (string) ($parent['parentOrderSn'] ?? '');
        $country = $this->country($parent, $address);
        $lines = $this->lines($shop, is_array($order['orderList'] ?? null) ? $order['orderList'] : []);
        $shipBy = isset($parent['expectShipLatestTime']) ? CarbonImmutable::createFromTimestamp((int) $parent['expectShipLatestTime']) : null;

        $meta = array_filter([
            '_temu_parent_order_sn' => $sn,
            '_temu_order_sns' => implode(',', array_column($lines, 'order_sn')),
            '_temu_ship_by' => $shipBy?->toIso8601String(),
        ], fn (?string $value) => $value !== null && $value !== '');

        return [
            'status' => 'processing',
            'set_paid' => true,
            'payment_method' => 'temu',
            'payment_method_title' => 'Temu',
            'transaction_id' => $sn,
            'created_via' => 'temu',
            'customer_id' => 0,
            'currency' => $shop->currency,
            'billing' => [
                'first_name' => 'Temu',
                'company' => 'Temu',
                'country' => $country,
            ],
            'shipping' => $this->shipping($address, $country),
            'line_items' => array_map(fn (array $line) => $line['item'], $lines),
            'shipping_lines' => [[
                'method_id' => 'temu',
                'method_title' => 'Temu',
                'total' => '0',
            ]],
            'meta_data' => array_map(
                fn (string $key, string $value) => ['key' => $key, 'value' => $value],
                array_keys($meta),
                array_values($meta),
            ),
        ];
    }

    /**
     * Write the private note that goes on the WooCommerce order.
     *
     * @param  array<string, mixed>  $order
     * @param  array<string, mixed>  $payload  The request handle() built.
     */
    public function note(array $order, array $payload): string
    {
        $parent = is_array($order['parentOrderMap'] ?? null) ? $order['parentOrderMap'] : [];
        $note = 'Imported from Temu order '.($parent['parentOrderSn'] ?? '').'.';

        if (isset($parent['expectShipLatestTime'])) {
            $note .= ' Ship by '.CarbonImmutable::createFromTimestamp((int) $parent['expectShipLatestTime'])
                ->timezone('Europe/Zurich')->format('d.m.Y H:i').' (Swiss time).';
        }

        $unmatched = array_filter($payload['line_items'], fn (array $item) => ! isset($item['product_id']));

        if ($unmatched !== []) {
            $note .= ' Not found in the catalog, so stock was not changed: '
                .implode(', ', array_column($unmatched, 'name')).'.';
        }

        return $note.' Confirm the shipment in Temu Seller Center.';
    }

    /**
     * Build the line items, priced at the base price Temu pays.
     *
     * @param  array<int, mixed>  $orderList
     * @return array<int, array{order_sn: string, item: array<string, mixed>}>
     */
    private function lines(Shop $shop, array $orderList): array
    {
        $lines = [];

        foreach ($orderList as $line) {
            if (! is_array($line)) {
                continue;
            }

            $quantity = (int) ($line['quantity'] ?? 0);

            if ($quantity <= 0) {
                continue;
            }

            [$record, $product] = $this->match($shop, $line);

            if ($record?->sent_price === null) {
                throw new RuntimeException(__('No Temu price is known for :name.', ['name' => $line['goodsName'] ?? $line['skuId'] ?? '?']));
            }

            $total = $this->net($shop, (float) $record->sent_price * $quantity);

            $item = [
                'quantity' => $quantity,
                'subtotal' => $total,
                'total' => $total,
                'meta_data' => [['key' => '_temu_order_sn', 'value' => (string) ($line['orderSn'] ?? '')]],
            ];

            if ($product?->remote_id !== null) {
                $item['product_id'] = $product->remote_id;
            } else {
                $item['name'] = trim(($line['goodsName'] ?? '').' '.($line['spec'] ?? '')) ?: (string) ($line['skuId'] ?? 'Temu item');
            }

            $lines[] = ['order_sn' => (string) ($line['orderSn'] ?? ''), 'item' => $item];
        }

        if ($lines === []) {
            throw new RuntimeException(__('The Temu order has no items to import.'));
        }

        return $lines;
    }

    /**
     * Find what was sent to Temu for an order line, and the product it is.
     *
     * Temu's SKU id is matched first; the SKU code Temu was given is the
     * fallback, for a product whose Temu ids were not recorded.
     *
     * @param  array<string, mixed>  $line
     * @return array{0: TemuProduct|null, 1: Product|null}
     */
    private function match(Shop $shop, array $line): array
    {
        $record = isset($line['skuId'])
            ? $shop->temuProducts()->where('sku_id', (int) $line['skuId'])->first()
            : null;

        $codes = array_filter(array_map(
            fn (mixed $product) => is_array($product) ? ($product['extCode'] ?? null) : null,
            is_array($line['productList'] ?? null) ? $line['productList'] : [],
        ), 'is_string');

        $record ??= $codes === [] ? null : $shop->temuProducts()->whereIn('external_sku_id', $codes)->first();

        $product = $record->product
            ?? ($codes === [] ? null : $shop->products()->whereIn('sku', $codes)->first());

        return [$record, $product];
    }

    /**
     * Take the tax out of a price that includes it.
     *
     * WooCommerce reads REST line totals as before tax and adds the tax
     * itself, so a shop whose prices include tax sends the net amount and
     * the order total comes out at the price Temu pays.
     */
    private function net(Shop $shop, float $gross): string
    {
        $net = $shop->prices_include_tax
            ? $gross / (1 + (float) $shop->tax_rate / 100)
            : $gross;

        return number_format($net, 4, '.', '');
    }

    /**
     * Build the buyer's shipping address.
     *
     * @param  array<string, mixed>  $address
     * @return array<string, string>
     */
    private function shipping(array $address, string $country): array
    {
        $names = is_array($address['addressExtra'] ?? null) ? $address['addressExtra'] : [];
        $first = trim((string) ($names['firstName'] ?? ''));
        $last = trim((string) ($names['lastName'] ?? ''));

        if ($first === '' && $last === '') {
            $parts = explode(' ', trim((string) ($address['receiptName'] ?? '')), 2);
            [$first, $last] = [$parts[0], $parts[1] ?? ''];
        }

        $state = trim((string) ($address['regionName2'] ?? ''));

        return [
            'first_name' => $first,
            'last_name' => $last,
            'address_1' => trim((string) ($address['addressLine1'] ?? '')),
            'address_2' => trim(implode(' ', array_filter([
                trim((string) ($address['addressLine2'] ?? '')),
                trim((string) ($address['addressLine3'] ?? '')),
            ]))),
            'city' => trim((string) ($address['regionName3'] ?? '')),
            'state' => $country === 'CH' ? (self::CANTONS[mb_strtolower($state)] ?? $state) : $state,
            'postcode' => trim((string) ($address['postCode'] ?? '')),
            'country' => $country,
            'phone' => trim((string) ($address['mobile'] ?? '')),
        ];
    }

    /**
     * Work out the country code for the order.
     *
     * @param  array<string, mixed>  $parent
     * @param  array<string, mixed>  $address
     */
    private function country(array $parent, array $address): string
    {
        $country = self::REGIONS[(int) ($parent['regionId'] ?? 0)]
            ?? self::COUNTRIES[mb_strtolower(trim((string) ($address['regionName1'] ?? '')))]
            ?? null;

        if ($country === null) {
            throw new RuntimeException(__('The Temu order ships to a country this app does not know: :country.', ['country' => $address['regionName1'] ?? $parent['regionId'] ?? '?']));
        }

        return $country;
    }
}
