<?php

declare(strict_types=1);

namespace Tests\Feature;

use Prism\Relay\RelayFactory;

it('registers relay in the service container', function (): void {
    $factory = app('relay');

    expect($factory)->toBeInstanceOf(RelayFactory::class);
});

it('merges config correctly', function (): void {
    $config = config('relay');

    expect($config)
        ->toBeArray()
        ->toHaveKey('servers')
        ->toHaveKey('cache_duration');

    // Verify that test config values were merged
    expect($config['servers'])
        ->toHaveKey('github')
        ->toHaveKey('puppeteer');
});

it('registers config publishing capability', function (): void {
    // Inspect the source code of the provider class
    $providerCode = file_get_contents(__DIR__.'/../../src/RelayServiceProvider.php');

    // Check if config is published with the expected tag
    expect($providerCode)->toContain('config/relay.php')
        ->and($providerCode)->toContain("'relay-config'");
});
