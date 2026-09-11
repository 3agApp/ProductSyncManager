<?php

namespace App\Services\WooCommerce\Exceptions;

class WooCommerceAuthenticationException extends WooCommerceException
{
    /**
     * Get a message safe to show the user.
     */
    public function userMessage(): string
    {
        return __('WooCommerce rejected the API credentials. Check the consumer key and secret.');
    }
}
