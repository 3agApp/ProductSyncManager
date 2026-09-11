<?php

namespace App\Services\WooCommerce\Exceptions;

use Exception;

class WooCommerceException extends Exception
{
    /**
     * Get a message safe to show the user.
     */
    public function userMessage(): string
    {
        return __('The WooCommerce store could not be reached.');
    }
}
