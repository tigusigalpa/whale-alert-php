<?php

declare(strict_types=1);

namespace Tigusigalpa\WhaleAlert\Exceptions;

class MissingApiKeyException extends WhaleAlertException
{
    public function __construct(string $message = 'API key is required for this endpoint.', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
