<?php

declare(strict_types=1);

namespace Tigusigalpa\WhaleAlert\WebSocket;

/**
 * Builds a subscribe_alerts message.
 */
class AlertSubscription
{
    public const MIN_VALUE_USD = 100000.0;
    public const TYPE = 'subscribe_alerts';

    private ?string $id;
    private array $blockchains;
    private array $symbols;
    private array $txTypes;
    private float $minValueUsd;

    public function __construct(
        ?string $id = null,
        array $blockchains = [],
        array $symbols = [],
        array $txTypes = [],
        float $minValueUsd = self::MIN_VALUE_USD,
    ) {
        $this->id = $id;
        $this->blockchains = $blockchains;
        $this->symbols = $symbols;
        $this->txTypes = $txTypes;
        $this->minValueUsd = $minValueUsd;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    /**
     * Validates the subscription filter.
     *
     * @throws \InvalidArgumentException
     */
    public function validate(): void
    {
        if ($this->minValueUsd < self::MIN_VALUE_USD) {
            throw new \InvalidArgumentException(
                sprintf('min_value_usd must be at least %.0f, got %.0f', self::MIN_VALUE_USD, $this->minValueUsd)
            );
        }
        if (empty($this->blockchains) && empty($this->symbols) && empty($this->txTypes)) {
            throw new \InvalidArgumentException(
                'At least one filter (blockchains, symbols, or tx_types) is required.'
            );
        }
    }

    /**
     * Returns the JSON-encoded subscription message.
     */
    public function toJson(): string
    {
        $payload = ['type' => self::TYPE];
        if ($this->id !== null) {
            $payload['id'] = $this->id;
        }
        if (!empty($this->blockchains)) {
            $payload['blockchains'] = $this->blockchains;
        }
        if (!empty($this->symbols)) {
            $payload['symbols'] = $this->symbols;
        }
        if (!empty($this->txTypes)) {
            $payload['tx_types'] = $this->txTypes;
        }
        $payload['min_value_usd'] = $this->minValueUsd;
        return json_encode($payload, JSON_THROW_ON_ERROR);
    }
}
