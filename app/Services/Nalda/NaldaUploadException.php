<?php

namespace App\Services\Nalda;

use RuntimeException;
use Throwable;

class NaldaUploadException extends RuntimeException
{
    public function __construct(
        private readonly string $userMessage,
        private readonly bool $permanent = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($userMessage, previous: $previous);
    }

    /**
     * The server refused the username or password.
     */
    public static function authentication(?Throwable $previous = null): self
    {
        return new self(__('Nalda rejected the SFTP username or password.'), permanent: true, previous: $previous);
    }

    /**
     * The server could not be reached.
     */
    public static function unreachable(?Throwable $previous = null): self
    {
        return new self(__('Could not connect to the Nalda SFTP server. Please try again later.'), previous: $previous);
    }

    /**
     * The connection worked but the file could not be written.
     */
    public static function writeFailed(?Throwable $previous = null): self
    {
        return new self(__('The product file could not be uploaded to Nalda. Please try again.'), previous: $previous);
    }

    /**
     * Get a message safe to show the user.
     */
    public function userMessage(): string
    {
        return $this->userMessage;
    }

    /**
     * Determine if retrying cannot help, so the job should stop at once.
     */
    public function isPermanent(): bool
    {
        return $this->permanent;
    }
}
