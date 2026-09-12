<?php

namespace App\Services\WooCommerce;

/**
 * One page of product categories from the WooCommerce REST API.
 *
 * Categories carry the parent links the product payload leaves out, which
 * is what lets the local tree be rebuilt.
 */
readonly class WooCommerceCategoryPage
{
    /**
     * @param  array<int, array<string, mixed>>  $categories
     */
    public function __construct(
        public array $categories,
        public ?int $totalPages = null,
    ) {
        //
    }

    /**
     * Determine if the page contained no categories.
     */
    public function isEmpty(): bool
    {
        return $this->categories === [];
    }
}
