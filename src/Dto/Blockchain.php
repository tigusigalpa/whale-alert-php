<?php

declare(strict_types=1);

namespace Tigusigalpa\WhaleAlert\Dto;

/**
 * Represents a supported blockchain and its symbols.
 */
class Blockchain extends Dto
{
    public function getName(): string
    {
        return $this->data['name'] ?? '';
    }

    /**
     * @return string[]
     */
    public function getSymbols(): array
    {
        return $this->data['symbols'] ?? [];
    }
}
