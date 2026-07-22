<?php

declare(strict_types=1);

namespace Tigusigalpa\WhaleAlert\Tests\Unit;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Tigusigalpa\WhaleAlert\Config;
use Tigusigalpa\WhaleAlert\WhaleAlertClient;
use Tigusigalpa\WhaleAlert\Exceptions\UnauthorizedException;
use Tigusigalpa\WhaleAlert\Exceptions\NotFoundException;
use Tigusigalpa\WhaleAlert\Exceptions\RateLimitException;
use Tigusigalpa\WhaleAlert\Exceptions\MissingApiKeyException;

class WhaleAlertClientTest extends TestCase
{
    private function createClient(array $responses, Config $config = null): WhaleAlertClient
    {
        $mock = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);
        $guzzle = new GuzzleClient(['handler' => $handlerStack]);
        $requestFactory = new HttpFactory();
        $streamFactory = new HttpFactory();

        if ($config === null) {
            $config = new Config('test-api-key');
        }

        return new WhaleAlertClient($config, $guzzle, $requestFactory, $streamFactory);
    }

    public function testGetSupportedBlockchains(): void
    {
        $client = $this->createClient([
            new Response(200, [], json_encode([
                ['name' => 'bitcoin', 'symbols' => ['BTC', 'USDT', 'EURT']],
                ['name' => 'ethereum', 'symbols' => ['ETH', 'USDT', 'USDC']],
            ])),
        ]);

        $chains = $client->getSupportedBlockchains();
        $this->assertCount(2, $chains);
        $this->assertSame('bitcoin', $chains[0]->getName());
        $this->assertSame(['BTC', 'USDT', 'EURT'], $chains[0]->getSymbols());
        $this->assertSame('ethereum', $chains[1]->getName());
    }

    public function testGetSupportedBlockchainsNoApiKey(): void
    {
        $client = $this->createClient(
            [new Response(200, [], json_encode([['name' => 'bitcoin', 'symbols' => ['BTC']]]))],
            new Config(''),
        );

        $chains = $client->getSupportedBlockchains();
        $this->assertCount(1, $chains);
    }

    public function testGetBlockchainStatus(): void
    {
        $client = $this->createClient([
            new Response(200, [], json_encode([
                'start_height' => 770789,
                'end_height' => 776799,
                'block_count' => 6011,
            ])),
        ]);

        $status = $client->getBlockchainStatus('bitcoin');
        $this->assertSame(770789, $status->getStartHeight());
        $this->assertSame(776799, $status->getEndHeight());
        $this->assertSame(6011, $status->getBlockCount());
    }

    public function testGetBlockchainStatusMissingApiKey(): void
    {
        $client = $this->createClient(
            [new Response(200)],
            new Config(''),
        );

        $this->expectException(MissingApiKeyException::class);
        $client->getBlockchainStatus('bitcoin');
    }

    public function testGetBlockchainStatusEmptyBlockchain(): void
    {
        $client = $this->createClient([new Response(200)]);
        $this->expectException(\InvalidArgumentException::class);
        $client->getBlockchainStatus('');
    }

    public function testGetBlockchainStatusUnauthorized(): void
    {
        $client = $this->createClient([
            new Response(401, [], json_encode(['error' => 'invalid api key'])),
        ]);

        $this->expectException(UnauthorizedException::class);
        $client->getBlockchainStatus('bitcoin');
    }

    public function testGetBlockchainStatusNotFound(): void
    {
        $client = $this->createClient([
            new Response(404, [], json_encode(['error' => 'not found'])),
        ]);

        $this->expectException(NotFoundException::class);
        $client->getBlockchainStatus('unknown');
    }

    public function testGetTransaction(): void
    {
        $client = $this->createClient([
            new Response(200, [], json_encode([
                'height' => 17616182,
                'index_in_block' => 6,
                'timestamp' => 1688420591,
                'hash' => '0xabc',
                'fee' => '0.00238487557',
                'fee_symbol' => 'ETH',
                'fee_symbol_price' => 1957.0,
                'sub_transactions' => [],
            ])),
        ]);

        $tx = $client->getTransaction('ethereum', '0xabc');
        $this->assertSame(17616182, $tx->getHeight());
        $this->assertSame('0xabc', $tx->getHash());
        $this->assertSame('0.00238487557', $tx->getFee());
        $this->assertSame('ETH', $tx->getFeeSymbol());
    }

    public function testGetTransactionEmptyHash(): void
    {
        $client = $this->createClient([new Response(200)]);
        $this->expectException(\InvalidArgumentException::class);
        $client->getTransaction('bitcoin', '');
    }

    public function testListTransactions(): void
    {
        $client = $this->createClient([
            new Response(200, [], json_encode([
                'transactions' => [
                    ['height' => 768801, 'hash' => '0xabc', 'fee' => '0.001', 'fee_symbol' => 'BTC', 'sub_transactions' => []],
                ],
                'next' => 'https://leviathan.whale-alert.io/bitcoin/transactions?start_height=768801&start_index=100&limit=100',
            ])),
        ]);

        $page = $client->listTransactions('bitcoin', ['start_height' => 768801, 'limit' => 100]);
        $this->assertCount(1, $page->getTransactions());
        $this->assertSame('0xabc', $page->getTransactions()[0]->getHash());
        $this->assertTrue($page->hasNext());
    }

    public function testListTransactionsMissingStartHeight(): void
    {
        $client = $this->createClient([new Response(200)]);
        $this->expectException(\InvalidArgumentException::class);
        $client->listTransactions('bitcoin', []);
    }

    public function testListTransactionsWithFilters(): void
    {
        $client = $this->createClient([
            new Response(200, [], json_encode(['transactions' => [], 'next' => ''])),
        ]);

        $page = $client->listTransactions('bitcoin', [
            'start_height' => 100,
            'symbol' => 'BTC',
            'transaction_type' => 'transfer',
            'order' => 'desc',
        ]);
        $this->assertFalse($page->hasNext());
    }

    public function testGetBlock(): void
    {
        $client = $this->createClient([
            new Response(200, [], json_encode([
                'timestamp' => 1688420591,
                'hash' => '0xblockhash',
                'transactions' => [],
            ])),
        ]);

        $block = $client->getBlock('bitcoin', 771103);
        $this->assertSame(1688420591, $block->getTimestamp());
        $this->assertSame('0xblockhash', $block->getHash());
    }

    public function testGetBlockInvalidHeight(): void
    {
        $client = $this->createClient([new Response(200)]);
        $this->expectException(\InvalidArgumentException::class);
        $client->getBlock('bitcoin', 0);
    }

    public function testGetAddressTransactions(): void
    {
        $client = $this->createClient([
            new Response(200, [], json_encode([
                'transactions' => [
                    ['height' => 100, 'hash' => '0xabc', 'fee' => '0.001', 'fee_symbol' => 'BTC', 'sub_transactions' => []],
                ],
                'next' => '',
            ])),
        ]);

        $page = $client->getAddressTransactions('bitcoin', '17BkMzRJt3XjEqp3TTmjmoj4dCZdjB9NEk', ['limit' => 50]);
        $this->assertCount(1, $page->getTransactions());
        $this->assertFalse($page->hasNext());
    }

    public function testGetAddressTransactionsEmptyAddress(): void
    {
        $client = $this->createClient([new Response(200)]);
        $this->expectException(\InvalidArgumentException::class);
        $client->getAddressTransactions('bitcoin', '');
    }

    public function testRateLimitException(): void
    {
        $client = $this->createClient([
            new Response(429, ['Retry-After' => '60'], json_encode(['error' => 'rate limited'])),
        ]);

        $this->expectException(RateLimitException::class);
        $client->getBlockchainStatus('bitcoin');
    }

    public function testRetryOn429ThenSuccess(): void
    {
        $client = $this->createClient(
            [
                new Response(429, ['Retry-After' => '0'], json_encode(['error' => 'rate limited'])),
                new Response(200, [], json_encode(['start_height' => 1, 'end_height' => 2, 'block_count' => 2])),
            ],
            new Config('test-api-key', 'https://leviathan.whale-alert.io', 30, 3, 1, 10),
        );

        $status = $client->getBlockchainStatus('bitcoin');
        $this->assertSame(1, $status->getStartHeight());
    }

    public function testNoRetryByDefault(): void
    {
        $client = $this->createClient([
            new Response(500, [], json_encode(['error' => 'server error'])),
        ]);

        $this->expectException(\Tigusigalpa\WhaleAlert\Exceptions\ServerException::class);
        $client->getBlockchainStatus('bitcoin');
    }

    public function testMalformedJson(): void
    {
        $client = $this->createClient([
            new Response(200, [], '{invalid json'),
        ]);

        $this->expectException(\Tigusigalpa\WhaleAlert\Exceptions\ApiException::class);
        $client->getBlockchainStatus('bitcoin');
    }

    public function testEmptyResponseBody(): void
    {
        $client = $this->createClient([
            new Response(200, [], ''),
        ]);

        $this->expectException(\Tigusigalpa\WhaleAlert\Exceptions\ApiException::class);
        $client->getBlockchainStatus('bitcoin');
    }
}
