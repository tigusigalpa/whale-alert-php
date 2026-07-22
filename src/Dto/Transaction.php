<?php

declare(strict_types=1);

namespace Tigusigalpa\WhaleAlert\Dto;

/**
 * Represents a normalized blockchain transaction.
 * Fee is kept as a string to preserve precision.
 */
class Transaction extends Dto
{
    public function getHeight(): int
    {
        return (int) ($this->data['height'] ?? 0);
    }

    public function getIndexInBlock(): int
    {
        return (int) ($this->data['index_in_block'] ?? 0);
    }

    public function getTimestamp(): int
    {
        return (int) ($this->data['timestamp'] ?? 0);
    }

    public function getHash(): string
    {
        return $this->data['hash'] ?? '';
    }

    public function getFee(): string
    {
        return (string) ($this->data['fee'] ?? '0');
    }

    public function getFeeSymbol(): string
    {
        return $this->data['fee_symbol'] ?? '';
    }

    /**
     * Returns the fee symbol price as-is (may be float or string from provider).
     */
    public function getFeeSymbolPrice(): mixed
    {
        return $this->data['fee_symbol_price'] ?? null;
    }

    /**
     * @return SubTransaction[]
     */
    public function getSubTransactions(): array
    {
        return array_map(
            fn(array $sub) => new SubTransaction($sub),
            $this->data['sub_transactions'] ?? [],
        );
    }
}
