<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Tigusigalpa\WhaleAlert\WebSocket\Client;
use Tigusigalpa\WhaleAlert\WebSocket\AlertSubscription;
use Tigusigalpa\WhaleAlert\WebSocket\EventType;

$apiKey = getenv('WHALE_ALERT_API_KEY');
if (!$apiKey) {
    fwrite(STDERR, "WHALE_ALERT_API_KEY environment variable is required\n");
    exit(1);
}

$wsUrl = sprintf('wss://leviathan.whale-alert.io/ws?api_key=%s', $apiKey);

$client = new Client(
    url: $wsUrl,
    maxReconnects: 5,
    reconnectDelayMs: 1000,
    reconnectMaxDelayMs: 30000,
);

$client->onMessage(function ($message) {
    if ($message->type === EventType::Alert && $message->alert !== null) {
        $a = $message->alert;
        printf("[ALERT] %s %s: %s -> %s | %s\n",
            $a['blockchain'] ?? '',
            $a['transaction_type'] ?? '',
            $a['from'] ?? '',
            $a['to'] ?? '',
            $a['text'] ?? ''
        );
        if (isset($a['amounts'])) {
            foreach ($a['amounts'] as $amount) {
                printf("  %.2f %s ($%.2f)\n", $amount['amount'], $amount['symbol'], $amount['value_usd']);
            }
        }
    } elseif ($message->type === EventType::Social && $message->social !== null) {
        printf("[SOCIAL] %s: %s\n", $message->social['blockchain'] ?? '', $message->social['text'] ?? '');
    } elseif ($message->type === EventType::SubscribedAlerts) {
        printf("[SUBSCRIBED] alerts id=%s\n", $message->alertConfirm['id'] ?? '');
    }
});

$client->onError(function (\Throwable $e) {
    fprintf(STDERR, "[ERROR] %s\n", $e->getMessage());
});

$client->connect();

$client->subscribeAlerts(new AlertSubscription(
    id: 'my-subscription',
    blockchains: ['ethereum', 'bitcoin'],
    minValueUsd: 500000,
));

$client->listen();
