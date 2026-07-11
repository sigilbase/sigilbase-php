<?php

declare(strict_types=1);

namespace Sigilbase\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Sigilbase\Client;

/**
 * Binds a configured Client into the container from config/sigilbase.php.
 * Auto-discovered; publish the config with:
 *
 *     php artisan vendor:publish --tag=sigilbase-config
 */
final class SigilbaseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/sigilbase.php', 'sigilbase');

        $this->app->singleton(Client::class, static function (Application $app): Client {
            /** @var Repository $config */
            $config = $app->make('config');

            $key = $config->get('sigilbase.key');
            $baseUrl = $config->get('sigilbase.base_url');
            $maxRetries = $config->get('sigilbase.max_retries', 3);

            if (! is_string($key) || trim($key) === '') {
                throw new InvalidArgumentException(
                    'No Sigilbase API key is configured. Set SIGILBASE_KEY in your environment '
                    .'or publish and edit config/sigilbase.php.'
                );
            }

            if (! is_string($baseUrl) || $baseUrl === '') {
                throw new InvalidArgumentException('The sigilbase.base_url config value must be a URL.');
            }

            return Client::create(
                apiKey: $key,
                baseUrl: $baseUrl,
                maxRetries: is_numeric($maxRetries) ? (int) $maxRetries : 3,
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/sigilbase.php' => $this->app->configPath('sigilbase.php'),
            ], 'sigilbase-config');
        }
    }
}
