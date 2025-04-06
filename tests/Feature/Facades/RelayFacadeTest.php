<?php

declare(strict_types=1);

namespace Tests\Feature\Facades;

use Prism\Relay\Facades\Relay;
use Prism\Relay\Relay as RelayClass;
use Prism\Relay\RelayFactory;

it('resolves the correct facade accessor', function (): void {
    $factory = Relay::getFacadeRoot();

    expect($factory)->toBeInstanceOf(RelayFactory::class);
});

it('forwards make method calls to the factory', function (): void {
    // Configure a test server
    config()->set('relay.servers.facade_test', [
        'url' => 'http://example.com/api',
        'timeout' => 30,
    ]);

    // Create a relay instance and verify its configuration
    $relay = Relay::make('facade_test');

    expect($relay)
        ->toBeInstanceOf(RelayClass::class)
        ->and($relay->getServerName())
        ->toBe('facade_test');
});

it('can get tools through the facade', function (): void {
    // Configure a test server
    config()->set('relay.servers.tools_test', [
        'url' => 'http://example.com/api',
        'timeout' => 30,
    ]);

    // Mock the actual HTTP calls that would happen under the hood
    $mock = $this->mock(RelayFactory::class);
    $mock->shouldReceive('tools')
        ->once()
        ->with('tools_test')
        ->andReturn([
            new \Prism\Prism\Tool,
            new \Prism\Prism\Tool,
        ]);

    app()->instance('relay', $mock);

    $tools = Relay::tools('tools_test');

    expect($tools)
        ->toBeArray()
        ->toHaveCount(2);
});
