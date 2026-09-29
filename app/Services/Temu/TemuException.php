<?php

namespace App\Services\Temu;

use RuntimeException;
use Throwable;

class TemuException extends RuntimeException
{
    public function __construct(
        private readonly string $userMessage,
        private readonly bool $connectionProblem = false,
        private readonly bool $retryable = false,
        private readonly ?int $temuCode = null,
        ?Throwable $previous = null,
        private readonly bool $rateLimit = false,
    ) {
        parent::__construct($userMessage, previous: $previous);
    }

    /**
     * Temu refused the app key, secret or access token.
     */
    public static function authentication(string $detail = '', ?int $code = null): self
    {
        $message = __('Temu rejected the app key, secret or access token.');

        return new self(
            $detail === '' ? $message : $message.' '.$detail,
            connectionProblem: true,
            temuCode: $code,
        );
    }

    /**
     * Temu could not be reached, or answered with a server error.
     */
    public static function unreachable(?Throwable $previous = null): self
    {
        return new self(
            __('Could not reach Temu. Please try again later.'),
            connectionProblem: true,
            retryable: true,
            previous: $previous,
        );
    }

    /**
     * Too many requests were sent in too short a time.
     */
    public static function rateLimited(): self
    {
        return new self(
            __('Temu is limiting requests. The sync will continue shortly.'),
            connectionProblem: true,
            retryable: true,
            rateLimit: true,
        );
    }

    /**
     * Temu answered with something that is not a gateway response.
     */
    public static function unexpected(): self
    {
        return new self(
            __('Temu returned an unexpected response. Please try again.'),
            connectionProblem: true,
            retryable: true,
        );
    }

    /**
     * Temu refused this one request, with its own explanation.
     *
     * Temu answers a passing fault of its own ("System error, please try
     * again later") the same way it refuses a product, so that is told
     * apart by what the message says, as credential problems are.
     */
    public static function rejected(string $temuMessage, ?int $code = null): self
    {
        $temuMessage = trim($temuMessage);

        return new self(
            $temuMessage === '' ? __('Temu refused the request (code :code).', ['code' => $code ?? '?']) : $temuMessage,
            retryable: preg_match('/system error|try again later|time ?out|busy/i', $temuMessage) === 1,
            temuCode: $code,
        );
    }

    /**
     * Get a message safe to show the user.
     */
    public function userMessage(): string
    {
        return $this->userMessage;
    }

    /**
     * Determine if the problem is with the connection rather than one request,
     * so carrying on with other products would fail the same way.
     */
    public function isConnectionProblem(): bool
    {
        return $this->connectionProblem;
    }

    /**
     * Determine if trying again later may succeed.
     */
    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    /**
     * Determine if Temu refused because too many requests were sent.
     */
    public function isRateLimited(): bool
    {
        return $this->rateLimit;
    }

    /**
     * Get Temu's error code, when it sent one.
     */
    public function temuCode(): ?int
    {
        return $this->temuCode;
    }
}
