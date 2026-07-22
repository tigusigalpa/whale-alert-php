<?php

declare(strict_types=1);

namespace Tigusigalpa\WhaleAlert\Dto;

/**
 * Represents an input or output address in a sub-transaction.
 * Amount is kept as a string to preserve precision.
 */
class Address extends Dto
{
    public function getAmount(): string
    {
        return (string) ($this->data['amount'] ?? '0');
    }

    public function getAddress(): string
    {
        return $this->data['address'] ?? '';
    }

    public function getOwner(): ?string
    {
        return $this->data['owner'] ?? null;
    }
}
