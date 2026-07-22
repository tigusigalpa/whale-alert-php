<?php

declare(strict_types=1);

namespace Tigusigalpa\WhaleAlert\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tigusigalpa\WhaleAlert\Config;

class ConfigTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $config = new Config('test-key');
        $this->assertSame('test-key', $config->getApiKey());
        $this->assertSame('https://leviathan.whale-alert.io', $config->getBaseUrl());
        $this->assertSame(30, $config->getTimeout());
        $this->assertSame(0, $config->getMaxRetries());
        $this->assertSame(500, $config->getRetryDelayMs());
        $this->assertSame(10000, $config->getRetryMaxDelayMs());
        $this->assertStringStartsWith('whale-alert-php/', $config->getUserAgent());
    }

    public function testCustomValues(): void
    {
        $config = new Config(
            apiKey: 'my-key',
            baseUrl: 'https://custom.example.com/',
            timeout: 60,
            maxRetries: 5,
            retryDelayMs: 1000,
            retryMaxDelayMs: 30000,
            userAgent: 'custom/1.0',
        );
        $this->assertSame('https://custom.example.com', $config->getBaseUrl());
        $this->assertSame(60, $config->getTimeout());
        $this->assertSame(5, $config->getMaxRetries());
        $this->assertSame(1000, $config->getRetryDelayMs());
        $this->assertSame(30000, $config->getRetryMaxDelayMs());
        $this->assertSame('custom/1.0', $config->getUserAgent());
    }

    public function testInvalidTimeout(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Config(timeout: 0);
    }

    public function testInvalidMaxRetries(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Config(maxRetries: -1);
    }

    public function testInvalidRetryDelay(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Config(retryDelayMs: 0);
    }

    public function testInvalidRetryMaxDelay(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Config(retryMaxDelayMs: 0);
    }

    public function testFromArray(): void
    {
        $config = Config::fromArray([
            'api_key' => 'array-key',
            'base_url' => 'https://from-array.example.com',
            'timeout' => 45,
            'max_retries' => 3,
            'retry_delay_ms' => 200,
            'retry_max_delay_ms' => 5000,
        ]);
        $this->assertSame('array-key', $config->getApiKey());
        $this->assertSame('https://from-array.example.com', $config->getBaseUrl());
        $this->assertSame(45, $config->getTimeout());
        $this->assertSame(3, $config->getMaxRetries());
        $this->assertSame(200, $config->getRetryDelayMs());
        $this->assertSame(5000, $config->getRetryMaxDelayMs());
    }

    public function testFromArrayDefaults(): void
    {
        $config = Config::fromArray([]);
        $this->assertSame('', $config->getApiKey());
        $this->assertSame('https://leviathan.whale-alert.io', $config->getBaseUrl());
        $this->assertSame(30, $config->getTimeout());
    }
}
