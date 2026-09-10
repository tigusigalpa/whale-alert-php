<?php

declare(strict_types=1);

namespace Tigusigalpa\WhaleAlert\Http;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Tigusigalpa\WhaleAlert\Config;
use Tigusigalpa\WhaleAlert\Exceptions\ApiException;
use Tigusigalpa\WhaleAlert\Exceptions\RateLimitException;
use Tigusigalpa\WhaleAlert\Exceptions\UnauthorizedException;
use Tigusigalpa\WhaleAlert\Exceptions\ForbiddenException;
use Tigusigalpa\WhaleAlert\Exceptions\NotFoundException;
use Tigusigalpa\WhaleAlert\Exceptions\ValidationException;
use Tigusigalpa\WhaleAlert\Exceptions\ServerException;
use Tigusigalpa\WhaleAlert\Exceptions\MissingApiKeyException;

/**
 * HTTP client wrapper with retry logic and error handling.
 */
class Client
{
    private ClientInterface $httpClient;
    private RequestFactoryInterface $requestFactory;
    private StreamFactoryInterface $streamFactory;
    private Config $config;

    public function __construct(
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        Config $config,
    ) {
        $this->httpClient = $httpClient;
        $this->requestFactory = $requestFactory;
        $this->streamFactory = $streamFactory;
        $this->config = $config;
    }

    /**
     * Sends a GET request and returns the decoded JSON response.
     *
     * @param string $path The API path (e.g. "/bitcoin/transactions")
     * @param array<string, mixed> $params Query parameters
     * @param bool $requireAuth Whether the endpoint requires an API key
     * @return array<string|int, mixed>
     * @throws ApiException
     */
    public function get(string $path, array $params = [], bool $requireAuth = true): array
    {
        if ($requireAuth && $this->config->getApiKey() === '') {
            throw new MissingApiKeyException();
        }

        $url = $this->buildUrl($path, $params, $requireAuth);
        return $this->sendRequest('GET', $url);
    }

    /**
     * Sends a GET request to a full URL (used for pagination next URLs).
     *
     * @param string $url The full URL to request
     * @return array<string|int, mixed>
     * @throws ApiException
     */
    public function getUrl(string $url): array
    {
        if ($this->config->getApiKey() === '') {
            throw new MissingApiKeyException();
        }

        $safeUrl = $this->validateNextUrl($url);
        return $this->sendRequest('GET', $safeUrl);
    }

    /**
     * Sends the HTTP request with retry handling.
     *
     * @return array<string|int, mixed>
     */
    private function sendRequest(string $method, string $url): array
    {
        $maxAttempts = $this->config->getMaxRetries();
        $lastException = null;

        for ($attempt = 0; $attempt <= $maxAttempts; $attempt++) {
            $request = $this->requestFactory->createRequest($method, $url);
            $request = $request->withHeader('Accept', 'application/json');
            $request = $request->withHeader('User-Agent', $this->config->getUserAgent());

            try {
                $response = $this->httpClient->sendRequest($request);
            } catch (\Psr\Http\Client\ClientExceptionInterface $e) {
                if ($attempt < $maxAttempts) {
                    $this->sleep($this->backoff($attempt));
                    continue;
                }
                throw new ApiException('HTTP transport error: ' . $this->redactSensitive($e->getMessage()), 0, null, null, 0, $e);
            }

            $status = $response->getStatusCode();
            $body = (string) $response->getBody();

            if ($status >= 200 && $status < 300) {
                return $this->parseResponse($body);
            }

            $apiException = $this->buildException($status, $body, $response);

            if ($this->shouldRetry($status) && $attempt < $maxAttempts) {
                $delay = $this->backoff($attempt);
                $retryAfter = $this->parseRetryAfter($response);
                if ($retryAfter !== null && $retryAfter > 0) {
                    $delay = $retryAfter;
                }
                $this->sleep($delay);
                $lastException = $apiException;
                continue;
            }

            throw $apiException;
        }

        throw $lastException ?? new ApiException('Unexpected HTTP error');
    }

    /**
     * @return array<string|int, mixed>
     */
    private function parseResponse(string $body): array
    {
        if ($body === '') {
            throw new ApiException('Empty response body');
        }

        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ApiException('Failed to decode JSON response: ' . json_last_error_msg());
        }

        if (!is_array($data)) {
            throw new ApiException('Expected a JSON object or array response.');
        }

        return $data;
    }

    private function buildException(int $status, string $body, ResponseInterface $response): ApiException
    {
        $message = '';
        $data = json_decode($body, true);
        if (is_array($data)) {
            $message = $data['error'] ?? $data['message'] ?? '';
        }
        if (!is_string($message)) {
            $message = '';
        }
        if ($message === '') {
            $message = $this->statusText($status);
        }
        $message = $this->redactSensitive($message);

        $excerpt = $this->sanitizeBody($body, 512);
        $retryAfter = $this->parseRetryAfter($response);

        return match (true) {
            $status === 401 => new UnauthorizedException($message, $status, $excerpt, $retryAfter),
            $status === 403 => new ForbiddenException($message, $status, $excerpt, $retryAfter),
            $status === 404 => new NotFoundException($message, $status, $excerpt, $retryAfter),
            $status === 422 => new ValidationException($message, $status, $excerpt, $retryAfter),
            $status === 429 => new RateLimitException($message, $status, $excerpt, $retryAfter),
            $status >= 500 => new ServerException($message, $status, $excerpt, $retryAfter),
            default => new ApiException($message, $status, $excerpt, $retryAfter),
        };
    }

    private function shouldRetry(int $status): bool
    {
        return $status === 429 || ($status >= 500 && $status <= 599);
    }

    private function backoff(int $attempt): int
    {
        $delay = $this->config->getRetryDelayMs() * (1 << $attempt);
        if ($delay > $this->config->getRetryMaxDelayMs() || $delay <= 0) {
            return $this->config->getRetryMaxDelayMs();
        }
        return $delay;
    }

    private function sleep(int $milliseconds): void
    {
        if ($milliseconds <= 0) {
            return;
        }
        usleep($milliseconds * 1000);
    }

    private function parseRetryAfter(ResponseInterface $response): ?int
    {
        if (!$response->hasHeader('Retry-After')) {
            return null;
        }
        $value = $response->getHeaderLine('Retry-After');
        if (ctype_digit($value)) {
            return (int) $value;
        }
        $timestamp = strtotime($value);
        if ($timestamp !== false) {
            $diff = $timestamp - time();
            return $diff > 0 ? $diff : 0;
        }
        return null;
    }

    private function buildUrl(string $path, array $params, bool $requireAuth): string
    {
        if ($requireAuth && $this->config->getApiKey() !== '') {
            $params['api_key'] = $this->config->getApiKey();
        }
        $queryString = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $url = $this->config->getBaseUrl() . $path;
        if ($queryString !== '') {
            $url .= '?' . $queryString;
        }
        return $url;
    }

    /**
     * Validates that a next URL points to the same origin as the configured base URL.
     */
    private function validateNextUrl(string $url): string
    {
        $parsed = parse_url($url);
        if ($parsed === false) {
            throw new ApiException('Invalid next URL');
        }

        $baseParsed = parse_url($this->config->getBaseUrl());
        if ($baseParsed === false) {
            throw new ApiException('Invalid base URL configuration');
        }

        if (
            isset($parsed['user'])
            || isset($parsed['pass'])
            || !isset($parsed['scheme'], $parsed['host'])
            || $this->origin($parsed) !== $this->origin($baseParsed)
        ) {
            throw new ApiException(
                'Next URL origin does not match base URL origin'
            );
        }

        // Always use the configured API key rather than a value in a provider-supplied URL.
        if ($this->config->getApiKey() !== '') {
            $query = $parsed['query'] ?? '';
            parse_str($query, $queryParams);
            $queryParams['api_key'] = $this->config->getApiKey();
            $parsed['query'] = http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);
        }

        unset($parsed['fragment']);

        return $this->unparseUrl($parsed);
    }

    /**
     * Reassembles a parsed URL array back into a string.
     */
    private function unparseUrl(array $parsed): string
    {
        $scheme = isset($parsed['scheme']) ? $parsed['scheme'] . '://' : '';
        $host = $parsed['host'] ?? '';
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $path = $parsed['path'] ?? '';
        $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';
        $fragment = isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '';
        return $scheme . $host . $port . $path . $query . $fragment;
    }

    private function sanitizeBody(string $body, int $max): string
    {
        $s = strlen($body) > $max ? substr($body, 0, $max) : $body;
        $s = preg_replace('/api_key=([^&"\s]+)/', 'api_key=REDACTED', $s) ?? $s;
        return $this->redactSensitive($s);
    }

    /**
     * Returns a normalized origin including the effective port.
     *
     * @param array<string, mixed> $parsed
     */
    private function origin(array $parsed): string
    {
        $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
        $host = strtolower((string) ($parsed['host'] ?? ''));
        $port = $parsed['port'] ?? match ($scheme) {
            'http' => 80,
            'https' => 443,
            default => 0,
        };

        return $scheme . '://' . $host . ':' . $port;
    }

    private function redactSensitive(string $value): string
    {
        $apiKey = $this->config->getApiKey();
        if ($apiKey !== '') {
            $value = str_replace($apiKey, 'REDACTED', $value);
        }

        $value = preg_replace('/(api_key=)[^&"\s]+/i', '$1REDACTED', $value) ?? $value;
        return preg_replace('/("api_key"\s*:\s*")[^"]*(")/i', '$1REDACTED$2', $value) ?? $value;
    }

    private function statusText(int $status): string
    {
        $texts = [
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
        ];
        return $texts[$status] ?? 'Unknown Error';
    }
}
