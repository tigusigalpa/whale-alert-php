<?php

declare(strict_types=1);

namespace Tigusigalpa\WhaleAlert\Dto;

/**
 * Represents a page of transactions with a next URL for pagination.
 *
 * @template T of Transaction
 */
class TransactionPage extends Dto
{
    /**
     * @return Transaction[]
     */
    public function getTransactions(): array
    {
        return array_map(
            fn(array $tx) => new Transaction($tx),
            $this->data['transactions'] ?? [],
        );
    }

    public function getNext(): string
    {
        return $this->data['next'] ?? '';
    }

    public function hasNext(): bool
    {
        return $this->getNext() !== '';
    }
}
