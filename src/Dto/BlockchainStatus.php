<?php

declare(strict_types=1);

namespace Tigusigalpa\WhaleAlert\Dto;

/**
 * Represents the availability window of a blockchain.
 */
class BlockchainStatus extends Dto
{
    public function getStartHeight(): int
    {
        return (int) ($this->data['start_height'] ?? 0);
    }

    public function getEndHeight(): int
    {
        return (int) ($this->data['end_height'] ?? 0);
    }

    public function getBlockCount(): int
    {
        return (int) ($this->data['block_count'] ?? 0);
    }
}
