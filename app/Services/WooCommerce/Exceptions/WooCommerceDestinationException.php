<?php

namespace App\Services\WooCommerce\Exceptions;

class WooCommerceDestinationException extends WooCommerceException
{
    /**
     * Get a message safe to show the user.
     */
    public function userMessage(): string
    {
        return __('The store address points to a private or reserved network, so it cannot be reached.');
    }
}
