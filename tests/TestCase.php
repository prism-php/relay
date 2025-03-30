<?php

declare(strict_types=1);

namespace Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Prism\Relay\RelayServiceProvider;

abstract class TestCase extends BaseTestCase
{
    #[\Override]
    protected function getPackageProviders($app): array
    {
        return [
            RelayServiceProvider::class,
        ];
    }

    #[\Override]
    protected function defineEnvironment($app): void
    {
        // Set up Relay configuration
        $app['config']->set('relay.servers', [
            'github' => [
                'url' => 'http://localhost:8000/api',
                'api_key' => null,
                'timeout' => 30,
            ],
            'puppeteer' => [
                'url' => 'http://localhost:8001/api',
                'api_key' => null,
                'timeout' => 30,
            ],
        ]);

        $app['config']->set('relay.cache_duration', 0);
    }
}
