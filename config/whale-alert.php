<?php

declare(strict_types=1);

return [
    'api_key' => env('WHALE_ALERT_API_KEY', ''),
    'base_url' => env('WHALE_ALERT_BASE_URL', 'https://leviathan.whale-alert.io'),
    'timeout' => (int) env('WHALE_ALERT_TIMEOUT', 30),
    'max_retries' => (int) env('WHALE_ALERT_MAX_RETRIES', 0),
    'retry_delay_ms' => (int) env('WHALE_ALERT_RETRY_DELAY_MS', 500),
    'retry_max_delay_ms' => (int) env('WHALE_ALERT_RETRY_MAX_DELAY_MS', 10000),
    'user_agent' => env('WHALE_ALERT_USER_AGENT', 'whale-alert-php/1.0.0 (+https://github.com/tigusigalpa/whale-alert-php)'),
    'http_client' => env('WHALE_ALERT_HTTP_CLIENT'),
];
