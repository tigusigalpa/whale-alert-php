<?php

declare(strict_types=1);

namespace Tigusigalpa\WhaleAlert\Laravel;

use Illuminate\Support\Facades\Facade;
use Tigusigalpa\WhaleAlert\WhaleAlertClient;

/**
 * Laravel facade for Whale Alert.
 *
 * @method static \Tigusigalpa\WhaleAlert\Dto\Blockchain[] getSupportedBlockchains()
 * @method static \Tigusigalpa\WhaleAlert\Dto\BlockchainStatus getBlockchainStatus(string $blockchain)
 * @method static \Tigusigalpa\WhaleAlert\Dto\Transaction getTransaction(string $blockchain, string $hash)
 * @method static \Tigusigalpa\WhaleAlert\Dto\TransactionPage listTransactions(string $blockchain, array $options)
 * @method static \Tigusigalpa\WhaleAlert\Dto\TransactionPage listTransactionsNext(string $nextUrl)
 * @method static \Tigusigalpa\WhaleAlert\Dto\Block getBlock(string $blockchain, int $height)
 * @method static \Tigusigalpa\WhaleAlert\Dto\TransactionPage getAddressTransactions(string $blockchain, string $address, array $options = [])
 * @method static \Tigusigalpa\WhaleAlert\Dto\TransactionPage getAddressTransactionsNext(string $nextUrl)
 */
class WhaleAlertFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return WhaleAlertClient::class;
    }
}
