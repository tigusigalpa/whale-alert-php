<?php

declare(strict_types=1);

namespace Tigusigalpa\WhaleAlert;

/**
 * Immutable configuration for the Whale Alert API client.
 */
class Config
{
    private string $apiKey;
    private string $baseUrl;
    private int $timeout;
    private int $maxRetries;
    private int $retryDelayMs;
    private int $retryMaxDelayMs;
    private string $userAgent;

    public function __construct(
        string $apiKey = '',
        string $baseUrl = 'https://leviathan.whale-alert.io',
        int $timeout = 30,
        int $maxRetries = 0,
        int $retryDelayMs = 500,
        int $retryMaxDelayMs = 10000,
        string $userAgent = 'whale-alert-php/1.0.0 (+https://github.com/tigusigalpa/whale-alert-php)',
    ) {
        $parsedBaseUrl = parse_url($baseUrl);
        if (
            $parsedBaseUrl === false
            || !isset($parsedBaseUrl['scheme'], $parsedBaseUrl['host'])
            || !in_array(strtolower($parsedBaseUrl['scheme']), ['http', 'https'], true)
            || isset($parsedBaseUrl['user'])
            || isset($parsedBaseUrl['pass'])
            || isset($parsedBaseUrl['query'])
            || isset($parsedBaseUrl['fragment'])
        ) {
            throw new \InvalidArgumentException('Base URL must be an absolute HTTP(S) URL without credentials, query, or fragment.');
        }
        if ($timeout <= 0) {
            throw new \InvalidArgumentException('Timeout must be a positive integer.');
        }
        if ($maxRetries < 0) {
            throw new \InvalidArgumentException('Max retries must be zero or positive.');
        }
        if ($retryDelayMs <= 0) {
            throw new \InvalidArgumentException('Retry delay must be a positive integer.');
        }
        if ($retryMaxDelayMs <= 0) {
            throw new \InvalidArgumentException('Retry max delay must be a positive integer.');
        }

        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = $timeout;
        $this->maxRetries = $maxRetries;
        $this->retryDelayMs = $retryDelayMs;
        $this->retryMaxDelayMs = $retryMaxDelayMs;
        $this->userAgent = $userAgent;
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    public function getMaxRetries(): int
    {
        return $this->maxRetries;
    }

    public function getRetryDelayMs(): int
    {
        return $this->retryDelayMs;
    }

    public function getRetryMaxDelayMs(): int
    {
        return $this->retryMaxDelayMs;
    }

    public function getUserAgent(): string
    {
        return $this->userAgent;
    }

    /**
     * Creates a Config instance from an array (e.g. Laravel config).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            apiKey: $data['api_key'] ?? '',
            baseUrl: $data['base_url'] ?? 'https://leviathan.whale-alert.io',
            timeout: $data['timeout'] ?? 30,
            maxRetries: $data['max_retries'] ?? 0,
            retryDelayMs: $data['retry_delay_ms'] ?? 500,
            retryMaxDelayMs: $data['retry_max_delay_ms'] ?? 10000,
            userAgent: $data['user_agent'] ?? 'whale-alert-php/1.0.0 (+https://github.com/tigusigalpa/whale-alert-php)',
        );
    }
}
