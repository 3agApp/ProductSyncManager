<?php

namespace App\Support;

use App\Models\Shop;

/**
 * Amounts as WooCommerce's REST API takes them on an order.
 */
final class WooCommerceAmount
{
    /**
     * Take the tax out of an amount that includes it.
     *
     * WooCommerce reads REST line and shipping totals as before tax and adds
     * the tax itself, so a shop whose prices include tax sends the net amount
     * and the order total comes out at exactly what the marketplace pays.
     */
    public static function net(Shop $shop, float $gross): string
    {
        $net = $shop->prices_include_tax
            ? $gross / (1 + (float) $shop->tax_rate / 100)
            : $gross;

        return number_format($net, 4, '.', '');
    }
}
