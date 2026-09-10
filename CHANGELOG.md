# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- WebSocket keepalive pings with configurable interval (30 seconds by default),
  pong timeout detection, RFC 6455 handshake validation, fragmented text-frame
  support, and a 16 MiB inbound-message limit.
- GitHub Actions for the PHP/Laravel compatibility matrix, PHPUnit coverage
  uploads to Codecov, weekly CodeQL scans, dependency-review pull-request
  checks, and scheduled Dependabot updates.

### Fixed

- `WebSocket\Client::readBytes()` could spin in a tight busy-loop consuming
  100% CPU if the underlying stream socket timed out without reaching EOF.
  A stream timeout is now detected via `stream_get_meta_data()` and raised
  as a `WebSocketConnectionException`, which `listen()` now catches and
  routes through the registered error handler before evaluating reconnect
  behavior, matching the reconnect/error-handling contract for ordinary
  disconnects.
- WebSocket ping replies are now sent as RFC 6455 pong control frames rather
  than an invalid nested text frame; reconnect attempts are iterative, close
  failed transports, and reset after a successful recovery.
- REST path values are percent-encoded; pagination URLs must match the exact
  configured scheme, host, and effective port; and provider-supplied API keys
  are replaced with the configured key.
- API keys are redacted from HTTP transport exceptions and JSON error excerpts.
- Scalar JSON responses now raise an API exception instead of leaking a
  TypeError.

### Security

- Updated Guzzle and related HTTP packages plus league/commonmark to versions
  without the advisories reported by composer audit.

## [1.0.0] - 2024-01-01

### Added

- REST API client with full endpoint coverage:
  - `GET /status` — supported blockchains (public, no API key)
  - `GET /{blockchain}/status` — blockchain availability window
  - `GET /{blockchain}/transaction/{hash}` — single transaction lookup
  - `GET /{blockchain}/transactions` — paginated transaction listing
  - `GET /{blockchain}/block/{height}` — block at specific height
  - `GET /{blockchain}/address/{hash}/transactions` — address transactions (last 30 days)
- WebSocket client with subscription management:
  - `subscribe_alerts` with filter validation (min_value_usd >= 100,000)
  - `subscribe_socials` for social media alerts
  - Event decoding: alerts, socials, subscription confirmations, errors
  - Automatic reconnection with same subscription ID for 5-minute recovery
  - Configurable exponential backoff for reconnection
  - Graceful shutdown with close frame
  - Ping/pong handling
- Typed DTOs: Blockchain, BlockchainStatus, Transaction, SubTransaction, Address, Block, TransactionPage
- Typed exception hierarchy: ApiException, UnauthorizedException, ForbiddenException, NotFoundException, ValidationException, RateLimitException, ServerException, MissingApiKeyException
- Configurable retry policy for idempotent GET requests (429, 5xx)
- Retry-After header parsing (seconds and HTTP-date formats)
- Pagination with typed TransactionPage and safe next-URL following
- Financial precision: amounts and fees as strings
- API key security: redacted values in error excerpts
- PSR-18 HTTP client abstraction with Guzzle default
- Laravel integration: service provider, facade, config publishing
- Immutable Config with array factory method
- Runnable examples for REST and WebSocket APIs
- Comprehensive test suite with Guzzle MockHandler and Orchestra Testbench
- MIT license
