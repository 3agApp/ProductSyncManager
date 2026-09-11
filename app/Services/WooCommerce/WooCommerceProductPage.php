<?php

namespace App\Services\WooCommerce;

/**
 * One page of products from the WooCommerce REST API.
 *
 * The totals come from the X-WP-Total and X-WP-TotalPages headers, which some
 * hosts strip. When they are missing the sync reports indeterminate progress
 * rather than guessing.
 */
readonly class WooCommerceProductPage
{
    /**
     * @param  array<int, array<string, mixed>>  $products
     */
    public function __construct(
        public array $products,
        public ?int $total = null,
        public ?int $totalPages = null,
    ) {
        //
    }

    /**
     * Determine if the page contained no products.
     */
    public function isEmpty(): bool
    {
        return $this->products === [];
    }
}
