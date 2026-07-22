<?php

declare(strict_types=1);

namespace Tigusigalpa\WhaleAlert\Laravel;

use Illuminate\Support\ServiceProvider;
use Psr\Http\Client\ClientInterface;
use Tigusigalpa\WhaleAlert\Config;
use Tigusigalpa\WhaleAlert\WhaleAlertClient;

/**
 * Laravel service provider for Whale Alert.
 */
class WhaleAlertServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/whale-alert.php',
            'whale-alert',
        );

        $this->app->singleton(WhaleAlertClient::class, function ($app): WhaleAlertClient {
            $config = $app['config']->get('whale-alert', []);

            $httpClient = null;
            if (!empty($config['http_client']) && is_string($config['http_client'])) {
                $httpClient = $app->make($config['http_client']);
                if (!$httpClient instanceof ClientInterface) {
                    throw new \InvalidArgumentException(
                        'Configured Whale Alert HTTP client must implement ' . ClientInterface::class,
                    );
                }
            }

            $whaleAlertConfig = Config::fromArray($config);
            return new WhaleAlertClient($whaleAlertConfig, $httpClient);
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes(
                [
                    __DIR__ . '/../../config/whale-alert.php' => config_path('whale-alert.php'),
                ],
                'whale-alert-config',
            );
        }
    }
}
