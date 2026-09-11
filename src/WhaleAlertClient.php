<?php

declare(strict_types=1);

namespace Tigusigalpa\WhaleAlert;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Tigusigalpa\WhaleAlert\Dto\Block;
use Tigusigalpa\WhaleAlert\Dto\Blockchain;
use Tigusigalpa\WhaleAlert\Dto\BlockchainStatus;
use Tigusigalpa\WhaleAlert\Dto\Transaction;
use Tigusigalpa\WhaleAlert\Dto\TransactionPage;
use Tigusigalpa\WhaleAlert\Exceptions\MissingApiKeyException;
use Tigusigalpa\WhaleAlert\Http\Client as HttpClient;

/**
 * Whale Alert API client.
 *
 * Provides typed access to all documented REST endpoints.
 */
class WhaleAlertClient
{
    private HttpClient $http;

    /**
     * @param Config $config Client configuration
     * @param ClientInterface|null $httpClient PSR-18 HTTP client (defaults to Guzzle)
     * @param RequestFactoryInterface|null $requestFactory PSR-17 request factory
     */
    public function __construct(
        Config $config,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
    ) {
        if ($httpClient === null) {
            $httpClient = new GuzzleClient([
                'timeout' => $config->getTimeout(),
            ]);
        }
        if ($requestFactory === null) {
            $requestFactory = new HttpFactory();
        }
        $this->http = new HttpClient($httpClient, $requestFactory, $config);
    }

    /**
     * Get supported blockchains (public endpoint, no API key required).
     *
     * @return Blockchain[]
     */
    public function getSupportedBlockchains(): array
    {
        $data = $this->http->get('/status', [], false);
        return array_map(fn(array $chain) => new Blockchain($chain), $data);
    }

    /**
     * Get the availability window for a specific blockchain.
     *
     * @param string $blockchain The blockchain name (e.g. "bitcoin", "ethereum")
     * @throws MissingApiKeyException
     */
    public function getBlockchainStatus(string $blockchain): BlockchainStatus
    {
        if ($blockchain === '') {
            throw new \InvalidArgumentException('Blockchain is required.');
        }
        $data = $this->http->get('/' . rawurlencode($blockchain) . '/status');
        return new BlockchainStatus($data);
    }

    /**
     * Get a single transaction by its hash.
     *
     * @param string $blockchain The blockchain name
     * @param string $hash The transaction hash
     * @throws MissingApiKeyException
     */
    public function getTransaction(string $blockchain, string $hash): Transaction
    {
        if ($blockchain === '') {
            throw new \InvalidArgumentException('Blockchain is required.');
        }
        if ($hash === '') {
            throw new \InvalidArgumentException('Hash is required.');
        }
        $data = $this->http->get('/' . rawurlencode($blockchain) . '/transaction/' . rawurlencode($hash));
        return new Transaction($data);
    }

    /**
     * List transactions starting at a given height.
     *
     * @param string $blockchain The blockchain name
     * @param array{
     *   start_height?: int,
     *   symbol?: string,
     *   transaction_type?: string,
     *   limit?: int,
     *   start_index?: int,
     *   order?: string,
     *   format?: string
     * } $options Query options
     * @throws MissingApiKeyException
     */
    public function listTransactions(string $blockchain, array $options): TransactionPage
    {
        if ($blockchain === '') {
            throw new \InvalidArgumentException('Blockchain is required.');
        }
        if (empty($options['start_height'])) {
            throw new \InvalidArgumentException('start_height is required.');
        }

        $params = [];
        $params['start_height'] = (int) $options['start_height'];
        if (!empty($options['symbol'])) {
            $params['symbol'] = $options['symbol'];
        }
        if (!empty($options['transaction_type'])) {
            $params['transaction_type'] = $options['transaction_type'];
        }
        if (!empty($options['limit'])) {
            $params['limit'] = (int) $options['limit'];
        }
        if (!empty($options['start_index'])) {
            $params['start_index'] = (int) $options['start_index'];
        }
        if (!empty($options['order'])) {
            $params['order'] = $options['order'];
        }
        if (!empty($options['format'])) {
            $params['format'] = $options['format'];
        }

        $data = $this->http->get('/' . rawurlencode($blockchain) . '/transactions', $params);
        return new TransactionPage($data);
    }

    /**
     * Fetch the next page of transactions using the provider-supplied next URL.
     *
     * @param string $nextUrl The next URL from a TransactionPage
     * @throws MissingApiKeyException
     */
    public function listTransactionsNext(string $nextUrl): TransactionPage
    {
        if ($nextUrl === '') {
            throw new \InvalidArgumentException('Next URL is required.');
        }
        $data = $this->http->getUrl($nextUrl);
        return new TransactionPage($data);
    }

    /**
     * Get a block at a specific height.
     *
     * @param string $blockchain The blockchain name
     * @param int $height The block height
     * @throws MissingApiKeyException
     */
    public function getBlock(string $blockchain, int $height): Block
    {
        if ($blockchain === '') {
            throw new \InvalidArgumentException('Blockchain is required.');
        }
        if ($height <= 0) {
            throw new \InvalidArgumentException('Height must be positive.');
        }
        $data = $this->http->get('/' . rawurlencode($blockchain) . '/block/' . $height);
        return new Block($data);
    }

    /**
     * Get transactions for an address from the last 30 days.
     *
     * @param string $blockchain The blockchain name
     * @param string $address The address hash
     * @param array{
     *   symbol?: string,
     *   transaction_type?: string,
     *   limit?: int,
     *   start_index?: int,
     *   order?: string
     * } $options Query options
     * @throws MissingApiKeyException
     */
    public function getAddressTransactions(string $blockchain, string $address, array $options = []): TransactionPage
    {
        if ($blockchain === '') {
            throw new \InvalidArgumentException('Blockchain is required.');
        }
        if ($address === '') {
            throw new \InvalidArgumentException('Address is required.');
        }

        $params = [];
        if (!empty($options['symbol'])) {
            $params['symbol'] = $options['symbol'];
        }
        if (!empty($options['transaction_type'])) {
            $params['transaction_type'] = $options['transaction_type'];
        }
        if (!empty($options['limit'])) {
            $params['limit'] = (int) $options['limit'];
        }
        if (!empty($options['start_index'])) {
            $params['start_index'] = (int) $options['start_index'];
        }
        if (!empty($options['order'])) {
            $params['order'] = $options['order'];
        }

        $data = $this->http->get('/' . rawurlencode($blockchain) . '/address/' . rawurlencode($address) . '/transactions', $params);
        return new TransactionPage($data);
    }

    /**
     * Fetch the next page of address transactions using the provider-supplied next URL.
     *
     * @param string $nextUrl The next URL from a TransactionPage
     * @throws MissingApiKeyException
     */
    public function getAddressTransactionsNext(string $nextUrl): TransactionPage
    {
        if ($nextUrl === '') {
            throw new \InvalidArgumentException('Next URL is required.');
        }
        $data = $this->http->getUrl($nextUrl);
        return new TransactionPage($data);
    }
}
