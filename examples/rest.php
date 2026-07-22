<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Tigusigalpa\WhaleAlert\Config;
use Tigusigalpa\WhaleAlert\WhaleAlertClient;

$apiKey = getenv('WHALE_ALERT_API_KEY');
if (!$apiKey) {
    fwrite(STDERR, "WHALE_ALERT_API_KEY environment variable is required\n");
    exit(1);
}

$config = new Config(
    apiKey: $apiKey,
    maxRetries: 3,
    retryDelayMs: 500,
    retryMaxDelayMs: 10000,
);

$client = new WhaleAlertClient($config);

// Get supported blockchains (public endpoint)
$chains = $client->getSupportedBlockchains();
foreach ($chains as $chain) {
    printf("  %s: %s\n", $chain->getName(), implode(', ', $chain->getSymbols()));
}

// Get blockchain status
$status = $client->getBlockchainStatus('ethereum');
printf("Ethereum: %d-%d (%d blocks)\n", $status->getStartHeight(), $status->getEndHeight(), $status->getBlockCount());

// List transactions
$page = $client->listTransactions('ethereum', [
    'start_height' => $status->getStartHeight(),
    'limit' => 100,
]);

foreach ($page->getTransactions() as $tx) {
    printf("  tx %s: fee=%s %s\n", $tx->getHash(), $tx->getFee(), $tx->getFeeSymbol());
}

if ($page->hasNext()) {
    echo "  Next page available\n";
}
