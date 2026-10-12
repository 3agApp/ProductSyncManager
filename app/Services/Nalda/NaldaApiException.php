<?php

namespace App\Services\Nalda;

use RuntimeException;
use Throwable;

/**
 * A call to Nalda's seller API failed.
 */
class NaldaApiException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly bool $retryable = false,
        private readonly bool $rateLimit = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    /**
     * Nalda refused the API key.
     */
    public static function authentication(): self
    {
        return new self(__('Nalda rejected the API key. Create a new one in the Nalda Seller Portal under Orders → Settings.'));
    }

    /**
     * Nalda could not be reached, or answered with a server error.
     */
    public static function unreachable(?Throwable $previous = null): self
    {
        return new self(__('Could not reach Nalda. Please try again later.'), retryable: true, previous: $previous);
    }

    /**
     * Too many requests were sent in too short a time.
     */
    public static function rateLimited(): self
    {
        return new self(__('Nalda is limiting requests. Orders are checked again shortly.'), retryable: true, rateLimit: true);
    }

    /**
     * Nalda refused the request, with its own explanation.
     */
    public static function rejected(string $message): self
    {
        return new self(__('Nalda refused the request: :message', ['message' => $message !== '' ? $message : __('no reason given')]));
    }

    /**
     * Whether trying again later may succeed.
     */
    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    /**
     * Whether Nalda asked for fewer requests.
     */
    public function isRateLimited(): bool
    {
        return $this->rateLimit;
    }
}
