<?php

declare(strict_types=1);

namespace Tigusigalpa\WhaleAlert\Dto;

/**
 * Abstract base DTO that holds the raw response data.
 */
abstract class Dto
{
    protected array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function getRawData(): array
    {
        return $this->data;
    }
}
