<?php

namespace App\Services\WooCommerce\Exceptions;

class WooCommerceRequestException extends WooCommerceException
{
    /**
     * Create an exception describing a failed response.
     */
    public static function status(int $status, string $body): self
    {
        return new self(sprintf('WooCommerce responded with HTTP %d: %s', $status, mb_substr($body, 0, 200)));
    }

    /**
     * Get a message safe to show the user.
     */
    public function userMessage(): string
    {
        return __('The WooCommerce store returned an unexpected response.');
    }
}
