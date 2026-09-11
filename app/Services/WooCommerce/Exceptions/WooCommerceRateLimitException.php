<?php

namespace App\Services\WooCommerce\Exceptions;

class WooCommerceRateLimitException extends WooCommerceException
{
    /**
     * The number of seconds the store asked us to wait.
     */
    public int $retryAfter = 60;

    /**
     * Create an exception carrying the store's retry delay.
     */
    public static function retryAfter(int $seconds): self
    {
        $exception = new self('WooCommerce rate limited the request.');
        $exception->retryAfter = max(1, $seconds);

        return $exception;
    }

    /**
     * Get a message safe to show the user.
     */
    public function userMessage(): string
    {
        return __('The WooCommerce store is rate limiting requests. The sync will retry shortly.');
    }
}
