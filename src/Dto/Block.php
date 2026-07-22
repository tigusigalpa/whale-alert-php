<?php

declare(strict_types=1);

namespace Tigusigalpa\WhaleAlert\Dto;

/**
 * Represents a block at a specific height.
 */
class Block extends Dto
{
    public function getTimestamp(): int
    {
        return (int) ($this->data['timestamp'] ?? 0);
    }

    public function getHash(): string
    {
        return $this->data['hash'] ?? '';
    }

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
}
