<?php

declare(strict_types=1);

namespace Tigusigalpa\WhaleAlert\Tests\Feature;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Orchestra\Testbench\TestCase;
use Tigusigalpa\WhaleAlert\Laravel\WhaleAlertServiceProvider;
use Tigusigalpa\WhaleAlert\WhaleAlertClient;
use Tigusigalpa\WhaleAlert\Config;

class LaravelIntegrationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [WhaleAlertServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('whale-alert', [
            'api_key' => 'laravel-test-key',
            'base_url' => 'https://leviathan.whale-alert.io',
            'timeout' => 30,
            'max_retries' => 0,
            'retry_delay_ms' => 500,
            'retry_max_delay_ms' => 10000,
        ]);
    }

    public function testClientIsRegisteredAsSingleton(): void
    {
        $client1 = $this->app->make(WhaleAlertClient::class);
        $client2 = $this->app->make(WhaleAlertClient::class);
        $this->assertSame($client1, $client2);
    }

    public function testClientWorksFromContainer(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                ['name' => 'bitcoin', 'symbols' => ['BTC']],
            ])),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $guzzle = new GuzzleClient(['handler' => $handlerStack]);

        $config = Config::fromArray($this->app['config']->get('whale-alert'));
        $client = new WhaleAlertClient(
            $config,
            $guzzle,
            new HttpFactory(),
            new HttpFactory(),
        );

        $chains = $client->getSupportedBlockchains();
        $this->assertCount(1, $chains);
        $this->assertSame('bitcoin', $chains[0]->getName());
    }

    public function testConfigIsPublished(): void
    {
        $this->artisan('vendor:publish', [
            '--provider' => WhaleAlertServiceProvider::class,
            '--tag' => 'whale-alert-config',
        ])->assertExitCode(0);

        $this->assertFileExists(config_path('whale-alert.php'));
        @unlink(config_path('whale-alert.php'));
    }
}
