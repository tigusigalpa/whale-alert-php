# Whale Alert PHP/Laravel Client/SDK/Library

![Whale Alert PHP SDK](https://i.postimg.cc/BZR6rj7c/whale-alert-php-laravel.jpg)

[![Packagist](https://img.shields.io/packagist/v/tigusigalpa/whale-alert-php.svg)](https://packagist.org/packages/tigusigalpa/whale-alert-php)
[![Tests](https://github.com/tigusigalpa/whale-alert-php/actions/workflows/ci.yml/badge.svg)](https://github.com/tigusigalpa/whale-alert-php/actions/workflows/ci.yml)
[![Coverage](https://github.com/tigusigalpa/whale-alert-php/actions/workflows/coverage.yml/badge.svg)](https://github.com/tigusigalpa/whale-alert-php/actions/workflows/coverage.yml)
[![CodeQL](https://github.com/tigusigalpa/whale-alert-php/actions/workflows/codeql.yml/badge.svg)](https://github.com/tigusigalpa/whale-alert-php/actions/workflows/codeql.yml)
[![Codecov](https://codecov.io/gh/tigusigalpa/whale-alert-php/graph/badge.svg)](https://codecov.io/gh/tigusigalpa/whale-alert-php)
[![PHP Version](https://img.shields.io/packagist/php-v/tigusigalpa/whale-alert-php.svg)](https://packagist.org/packages/tigusigalpa/whale-alert-php)
[![MIT License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

An unofficial PHP client library for the [Whale Alert Enterprise API](https://developer.whale-alert.io/api-account/documentation).

> **Disclaimer:** This is an unofficial SDK and is not affiliated with, endorsed by, or sponsored by Whale Alert. All product names, logos, and brands are property of their respective owners.

## What is this?

This library makes it easy to talk to the Whale Alert Enterprise API from PHP. Whether you want to query transactions and blocks, check blockchain status, or stream real-time whale alerts over WebSocket, the client gives you typed, immutable DTOs and robust error handling out of the box.

It is built around a few ideas that should feel natural in modern PHP:

- It works with any PSR-18 HTTP client, so you are not locked into Guzzle.
- It includes an optional Laravel service provider and facade for framework projects.
- Monetary values stay as strings so you never lose precision.
- Retries, pagination, and WebSocket reconnection are opt-in but easy to enable.

## Features

- **REST API**: Full coverage of the documented endpoints — status, blockchain status, transactions, blocks, and address transactions.
- **WebSocket API**: Real-time alerts and socials with subscription management, automatic reconnection, ping/pong keep-alive, and event decoding.
- **Typed DTOs**: Immutable data transfer objects for all API responses, so your IDE can autocomplete fields.
- **Financial precision**: Amounts and fees are kept as `string` to avoid the rounding issues that come from `float`.
- **Framework-agnostic**: Uses PSR-18 HTTP client abstraction; works with Guzzle or any PSR-18 compatible client.
- **Laravel integration**: Optional service provider, facade, and config publishing.
- **Retry policy**: Configurable exponential backoff for idempotent GET requests when the API returns 429 or 5xx errors.
- **Pagination**: Typed page objects with a lazy iterator and safe next-URL following. The library validates next URLs against the configured base origin so you cannot accidentally follow a malicious link.
- **Error handling**: Typed exceptions (`UnauthorizedException`, `RateLimitException`, and others) for programmatic handling.
- **API key security**: Your API key is never logged, and error excerpts have `api_key` values redacted.

## Installation

Install the package with Composer:

```bash
composer require tigusigalpa/whale-alert-php
```

The package uses Guzzle by default, but you can substitute any PSR-18 HTTP client if you prefer.

## Quick Start

### REST API

This example creates a client, calls a public endpoint that does not need an API key, and then calls an authenticated endpoint and lists transactions:

```php
use Tigusigalpa\WhaleAlert\Config;
use Tigusigalpa\WhaleAlert\WhaleAlertClient;

// Create a config object. apiKey can be empty for the public /status endpoint,
// but authenticated endpoints require a real key.
$config = new Config(
    apiKey: getenv('WHALE_ALERT_API_KEY'),
    maxRetries: 3,
);

$client = new WhaleAlertClient($config);

// Public endpoint — no API key required.
// Returns the list of blockchains Whale Alert supports.
$chains = $client->getSupportedBlockchains();
foreach ($chains as $chain) {
    echo $chain->getName() . ': ' . implode(', ', $chain->getSymbols()) . "\n";
}

// Authenticated endpoint — requires a valid API key.
// Returns the current sync status for Ethereum.
$status = $client->getBlockchainStatus('ethereum');
echo "Ethereum: {$status->getStartHeight()}-{$status->getEndHeight()}\n";

// List transactions starting from the chain's current start height.
$page = $client->listTransactions('ethereum', [
    'start_height' => $status->getStartHeight(),
    'limit' => 100,
]);

foreach ($page->getTransactions() as $tx) {
    echo "  tx {$tx->getHash()}: fee={$tx->getFee()} {$tx->getFeeSymbol()}\n";
}
```

### WebSocket API

The WebSocket client streams real-time alerts. You register message and error handlers, connect, subscribe, and then call `listen()` to enter the read loop. Automatic reconnection is opt-in: set `maxReconnects` greater than zero. It sends RFC 6455 ping frames every 30 seconds by default and requires a pong within 60 seconds; set `pingInterval` to tune the ping cadence.

```php
use Tigusigalpa\WhaleAlert\WebSocket\Client;
use Tigusigalpa\WhaleAlert\WebSocket\AlertSubscription;
use Tigusigalpa\WhaleAlert\WebSocket\EventType;

$wsUrl = sprintf('wss://leviathan.whale-alert.io/ws?api_key=%s', getenv('WHALE_ALERT_API_KEY'));

// maxReconnects is the third constructor argument. We use a named argument
// so the default connection timeout (30 seconds) is left in place.
$client = new Client($wsUrl, maxReconnects: 5, pingInterval: 30);

// Called for every decoded message.
$client->onMessage(function ($message) {
    if ($message->type === EventType::Alert && $message->alert !== null) {
        echo "[ALERT] {$message->alert['blockchain']}: {$message->alert['text']}\n";
    }
});

// Called when a non-fatal error happens, such as a temporary disconnect.
$client->onError(function (\Throwable $e) {
    fwrite(STDERR, "[ERROR] {$e->getMessage()}\n");
});

$client->connect();

// Subscribe to Ethereum whale alerts worth at least $500,000.
$client->subscribeAlerts(new AlertSubscription(
    id: 'my-sub',
    blockchains: ['ethereum'],
    minValueUsd: 500000,
));

// listen() blocks until the connection closes.
$client->listen();
```

## Laravel Integration

If you are building a Laravel application, the package can register itself and expose a friendly facade.

### 1. Add the service provider

For Laravel 11 and later, providers are usually registered in `bootstrap/providers.php`:

```php
return [
    // ...
    Tigusigalpa\WhaleAlert\Laravel\WhaleAlertServiceProvider::class,
];
```

In older Laravel versions, add it to `config/app.php` instead.

### 2. Publish the config

Run this command to copy the default configuration into `config/whale-alert.php`:

```bash
php artisan vendor:publish --provider="Tigusigalpa\WhaleAlert\Laravel\WhaleAlertServiceProvider" --tag="whale-alert-config"
```

### 3. Set your environment variables

Add these lines to your `.env` file:

```dotenv
WHALE_ALERT_API_KEY=your-api-key
WHALE_ALERT_BASE_URL=https://leviathan.whale-alert.io
WHALE_ALERT_TIMEOUT=30
WHALE_ALERT_MAX_RETRIES=3
```

### 4. Use the facade

```php
use WhaleAlert;

$chains = WhaleAlert::getSupportedBlockchains();
$status = WhaleAlert::getBlockchainStatus('ethereum');
```

### 5. Or inject the client

If you prefer dependency injection, type-hint the client in a controller or command:

```php
use Tigusigalpa\WhaleAlert\WhaleAlertClient;

public function index(WhaleAlertClient $client)
{
    $chains = $client->getSupportedBlockchains();
    // ...
}
```

The service provider registers `WhaleAlertClient` as a singleton, so the same configured instance is reused throughout the request lifecycle.

## Configuration

### Config Options

The `Tigusigalpa\WhaleAlert\Config` class controls client behavior:

| Option | Description | Default |
|--------|-------------|---------|
| `apiKey` | API key for authenticated endpoints | `''` |
| `baseUrl` | API base URL | `https://leviathan.whale-alert.io` |
| `timeout` | HTTP timeout in seconds | 30 |
| `maxRetries` | Max retry attempts for idempotent GETs | 0 (disabled) |
| `retryDelayMs` | Initial retry delay in milliseconds | 500 |
| `retryMaxDelayMs` | Maximum retry delay cap in milliseconds | 10000 |
| `userAgent` | User-Agent header | `whale-alert-php/1.0.0` |

You can also build a `Config` from an associative array, which is useful when loading values from Laravel config or a DI container:

```php
use Tigusigalpa\WhaleAlert\Config;

$config = Config::fromArray([
    'api_key' => getenv('WHALE_ALERT_API_KEY'),
    'max_retries' => 3,
    'timeout' => 30,
]);
```

### Laravel Config

After publishing, `config/whale-alert.php` looks like this:

```php
return [
    'api_key' => env('WHALE_ALERT_API_KEY'),
    'base_url' => env('WHALE_ALERT_BASE_URL', 'https://leviathan.whale-alert.io'),
    'timeout' => env('WHALE_ALERT_TIMEOUT', 30),
    'max_retries' => env('WHALE_ALERT_MAX_RETRIES', 0),
    'retry_delay_ms' => env('WHALE_ALERT_RETRY_DELAY_MS', 500),
    'retry_max_delay_ms' => env('WHALE_ALERT_RETRY_MAX_DELAY_MS', 10000),
    'user_agent' => env('WHALE_ALERT_USER_AGENT', 'whale-alert-php/1.0.0 (+https://github.com/tigusigalpa/whale-alert-php)'),
    // 'http_client' => null, // optional service ID for a PSR-18 client
];
```

## Error Handling

Each API error is mapped to a typed exception. You can catch specific exceptions for fine-grained handling, or catch the base `ApiException` for a generic fallback.

```php
use Tigusigalpa\WhaleAlert\Exceptions\UnauthorizedException;
use Tigusigalpa\WhaleAlert\Exceptions\RateLimitException;
use Tigusigalpa\WhaleAlert\Exceptions\ApiException;

try {
    $status = $client->getBlockchainStatus('ethereum');
} catch (UnauthorizedException $e) {
    // The API key is missing or invalid. Check WHALE_ALERT_API_KEY.
} catch (RateLimitException $e) {
    // You are sending too many requests. Back off and try $e->getRetryAfter().
} catch (ApiException $e) {
    // Any other API error. Inspect $e->getStatusCode() and $e->getMessage().
}
```

### Exception Hierarchy

| Exception | HTTP Status | Typical cause |
|-----------|-------------|---------------|
| `UnauthorizedException` | 401 | Missing or invalid API key |
| `ForbiddenException` | 403 | Insufficient permissions |
| `NotFoundException` | 404 | Unknown blockchain, transaction, or block |
| `ValidationException` | 422 | Parameter validation failure |
| `RateLimitException` | 429 | Too many requests |
| `ServerException` | 5xx | Provider-side error |
| `MissingApiKeyException` | N/A (client-side) | An authenticated endpoint was called without an API key |

## Retry Policy

Retries are **disabled by default**. Enable them by setting `maxRetries` on the `Config` object or in your Laravel config.

```php
$config = new Config(
    apiKey: getenv('WHALE_ALERT_API_KEY'),
    maxRetries: 3,
    retryDelayMs: 500,
    retryMaxDelayMs: 10000,
);
```

The retry policy is designed to be safe and predictable:

- Only idempotent GET requests are retried. State-changing operations are never retried automatically.
- Retries happen on HTTP 429 (rate limited) and 5xx server errors.
- The 429 response can include a `Retry-After` header. When present, the client waits at least that long before the next attempt.
- Backoff is exponential: `retryDelayMs * 2^attempt`, capped at `retryMaxDelayMs`.

## Pagination

List endpoints return a `TransactionPage` that includes the current slice of transactions and an optional next URL. You can iterate manually or use the built-in helper.

### Manual next-page fetching

```php
$page = $client->listTransactions('ethereum', [
    'start_height' => $status->getStartHeight(),
    'limit' => 100,
]);

foreach ($page->getTransactions() as $tx) {
    echo $tx->getHash() . "\n";
}

// Fetch the next page. The URL is validated against the configured base origin.
if ($page->hasNext()) {
    $nextPage = $client->listTransactionsNext($page->getNext());
    // process $nextPage...
}
```

The same helpers exist for address transactions: `getAddressTransactions`, `getAddressTransactionsNext`.

## Financial Precision

Cryptocurrency amounts can be very small or very large, and PHP's `float` type cannot represent them exactly. For that reason, all monetary fields in this library (`fee`, `amount` in addresses, and similar) are kept as `string`.

Keep them as strings for display or pass them to a decimal-arithmetic package such as `brick/math`. Only cast to `float` if you fully understand the precision implications.

## API Reference

The client exposes one flat API that maps directly to the REST endpoints.

- **Status**
  - `getSupportedBlockchains()` — `GET /status` (public, no key needed)
  - `getBlockchainStatus(blockchain)` — `GET /{blockchain}/status`
- **Transactions**
  - `getTransaction(blockchain, hash)` — `GET /{blockchain}/transaction/{hash}`
  - `listTransactions(blockchain, options)` — `GET /{blockchain}/transactions`
  - `listTransactionsNext(nextUrl)` — Follow a pagination URL returned by a previous list call
- **Blocks**
  - `getBlock(blockchain, height)` — `GET /{blockchain}/block/{height}`
- **Addresses**
  - `getAddressTransactions(blockchain, address, options)` — `GET /{blockchain}/address/{hash}/transactions`
  - `getAddressTransactionsNext(nextUrl)` — Follow a pagination URL for address transactions

For the official API documentation, visit https://developer.whale-alert.io/api-account/documentation.

## Examples

Runnable examples are in the `examples/` directory:

- `examples/rest.php` — REST API usage
- `examples/websocket.php` — WebSocket alerts subscription

Run them from the repository root:

```bash
WHALE_ALERT_API_KEY=your-key php examples/rest.php
WHALE_ALERT_API_KEY=your-key php examples/websocket.php
```

## Testing

The project includes unit tests for the REST client, WebSocket client, DTOs, and error handling. Install the test dependencies and run PHPUnit:

```bash
composer install
vendor/bin/phpunit
```

## License

MIT — see [LICENSE](LICENSE)

## Author

Igor Sazonov — [github.com/tigusigalpa](https://github.com/tigusigalpa)
