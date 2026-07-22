<?php

declare(strict_types=1);

namespace Tigusigalpa\WhaleAlert\Exceptions;

/**
 * Base exception for all Whale Alert SDK errors.
 */
class WhaleAlertException extends \RuntimeException
{
    protected ?int $statusCode = null;
    protected ?string $bodyExcerpt = null;
    protected ?int $retryAfter = null;

    public function __construct(string $message, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    public function getBodyExcerpt(): ?string
    {
        return $this->bodyExcerpt;
    }

    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
