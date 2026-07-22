<?php

declare(strict_types=1);

namespace Tigusigalpa\WhaleAlert\Exceptions;

/**
 * Thrown when the API returns an error response (4xx or 5xx).
 */
class ApiException extends WhaleAlertException
{
    public function __construct(
        string $message,
        int $statusCode = 0,
        ?string $bodyExcerpt = null,
        ?int $retryAfter = null,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
        $this->statusCode = $statusCode;
        $this->bodyExcerpt = $bodyExcerpt;
        $this->retryAfter = $retryAfter;
    }
}
