<?php

declare(strict_types=1);

namespace Tigusigalpa\WhaleAlert\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tigusigalpa\WhaleAlert\Dto\Transaction;
use Tigusigalpa\WhaleAlert\Dto\TransactionPage;
use Tigusigalpa\WhaleAlert\Dto\Block;
use Tigusigalpa\WhaleAlert\Dto\Address;
use Tigusigalpa\WhaleAlert\Dto\SubTransaction;
use Tigusigalpa\WhaleAlert\Dto\BlockchainStatus;
use Tigusigalpa\WhaleAlert\Dto\Blockchain;

class DtoTest extends TestCase
{
    public function testBlockchain(): void
    {
        $dto = new Blockchain(['name' => 'bitcoin', 'symbols' => ['BTC', 'USDT']]);
        $this->assertSame('bitcoin', $dto->getName());
        $this->assertSame(['BTC', 'USDT'], $dto->getSymbols());
    }

    public function testBlockchainStatus(): void
    {
        $dto = new BlockchainStatus(['start_height' => 100, 'end_height' => 200, 'block_count' => 101]);
        $this->assertSame(100, $dto->getStartHeight());
        $this->assertSame(200, $dto->getEndHeight());
        $this->assertSame(101, $dto->getBlockCount());
    }

    public function testTransaction(): void
    {
        $dto = new Transaction([
            'height' => 17616182,
            'index_in_block' => 6,
            'timestamp' => 1688420591,
            'hash' => '0xabc',
            'fee' => '0.00238487557',
            'fee_symbol' => 'ETH',
            'fee_symbol_price' => 1957.0,
            'sub_transactions' => [],
        ]);
        $this->assertSame(17616182, $dto->getHeight());
        $this->assertSame('0xabc', $dto->getHash());
        $this->assertSame('0.00238487557', $dto->getFee());
        $this->assertSame('ETH', $dto->getFeeSymbol());
        $this->assertSame(1957.0, $dto->getFeeSymbolPrice());
        $this->assertSame([], $dto->getSubTransactions());
    }

    public function testTransactionWithSubTransactions(): void
    {
        $dto = new Transaction([
            'height' => 100,
            'hash' => '0xabc',
            'fee' => '0.001',
            'fee_symbol' => 'BTC',
            'sub_transactions' => [
                [
                    'symbol' => 'BTC',
                    'transaction_type' => 'transfer',
                    'inputs' => [['amount' => '1.5', 'address' => 'addr1', 'owner' => 'nexo']],
                    'outputs' => [['amount' => '0', 'address' => 'addr2']],
                ],
            ],
        ]);
        $subs = $dto->getSubTransactions();
        $this->assertCount(1, $subs);
        $this->assertSame('BTC', $subs[0]->getSymbol());
        $this->assertSame('transfer', $subs[0]->getTransactionType());

        $inputs = $subs[0]->getInputs();
        $this->assertCount(1, $inputs);
        $this->assertSame('1.5', $inputs[0]->getAmount());
        $this->assertSame('addr1', $inputs[0]->getAddress());
        $this->assertSame('nexo', $inputs[0]->getOwner());

        $outputs = $subs[0]->getOutputs();
        $this->assertCount(1, $outputs);
        $this->assertSame('0', $outputs[0]->getAmount());
        $this->assertNull($outputs[0]->getOwner());
    }

    public function testTransactionPage(): void
    {
        $dto = new TransactionPage([
            'transactions' => [],
            'next' => 'https://example.com/next',
        ]);
        $this->assertSame([], $dto->getTransactions());
        $this->assertSame('https://example.com/next', $dto->getNext());
        $this->assertTrue($dto->hasNext());
    }

    public function testTransactionPageNoNext(): void
    {
        $dto = new TransactionPage(['transactions' => [], 'next' => '']);
        $this->assertFalse($dto->hasNext());
    }

    public function testBlock(): void
    {
        $dto = new Block([
            'timestamp' => 1688420591,
            'hash' => '0xblockhash',
            'transactions' => [],
        ]);
        $this->assertSame(1688420591, $dto->getTimestamp());
        $this->assertSame('0xblockhash', $dto->getHash());
        $this->assertSame([], $dto->getTransactions());
    }

    public function testAddressDefaults(): void
    {
        $dto = new Address([]);
        $this->assertSame('0', $dto->getAmount());
        $this->assertSame('', $dto->getAddress());
        $this->assertNull($dto->getOwner());
    }

    public function testGetRawData(): void
    {
        $data = ['name' => 'bitcoin', 'symbols' => ['BTC']];
        $dto = new Blockchain($data);
        $this->assertSame($data, $dto->getRawData());
    }
}
