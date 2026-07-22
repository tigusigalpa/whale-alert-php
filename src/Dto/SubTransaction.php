<?php

declare(strict_types=1);

namespace Tigusigalpa\WhaleAlert\Dto;

/**
 * Represents a single currency/type split within a transaction.
 */
class SubTransaction extends Dto
{
    public function getSymbol(): string
    {
        return $this->data['symbol'] ?? '';
    }

    public function getTransactionType(): string
    {
        return $this->data['transaction_type'] ?? '';
    }

    /**
     * @return Address[]
     */
    public function getInputs(): array
    {
        return array_map(
            fn(array $input) => new Address($input),
            $this->data['inputs'] ?? [],
        );
    }

    /**
     * @return Address[]
     */
    public function getOutputs(): array
    {
        return array_map(
            fn(array $output) => new Address($output),
            $this->data['outputs'] ?? [],
        );
    }
}
