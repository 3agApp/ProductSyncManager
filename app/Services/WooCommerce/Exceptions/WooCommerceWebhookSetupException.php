<?php

namespace App\Services\WooCommerce\Exceptions;

class WooCommerceWebhookSetupException extends WooCommerceException
{
    public function userMessage(): string
    {
        return $this->getMessage();
    }
}
