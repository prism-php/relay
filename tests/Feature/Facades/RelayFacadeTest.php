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

it('can make relay with custom config through facade', function (): void {
    $customConfig = [
        'transport' => \Prism\Relay\Enums\Transport::Http,
        'url' => 'http://custom.example.com/api',
        'timeout' => 45,
    ];

    $relay = Relay::make('custom_facade_server', $customConfig);

    expect($relay)
        ->toBeInstanceOf(RelayClass::class)
        ->and($relay->getServerName())
        ->toBe('custom_facade_server');
});

it('can get tools with custom config through facade', function (): void {
    $customConfig = [
        'transport' => \Prism\Relay\Enums\Transport::Http,
        'url' => 'http://tools.example.com/api',
        'timeout' => 30,
    ];

    // Mock the factory to test the facade call
    $mock = $this->mock(RelayFactory::class);
    $mock->shouldReceive('tools')
        ->once()
        ->with('custom_tools_server', $customConfig)
        ->andReturn([
            new \Prism\Prism\Tool,
        ]);

    app()->instance('relay', $mock);

    $tools = Relay::tools('custom_tools_server', $customConfig);

    expect($tools)
        ->toBeArray()
        ->toHaveCount(1);
});

it('facade make method works without config parameter', function (): void {
    config()->set('relay.servers.standard_test', [
        'url' => 'http://standard.example.com/api',
        'timeout' => 30,
    ]);

    $relay = Relay::make('standard_test');

    expect($relay)
        ->toBeInstanceOf(RelayClass::class)
        ->and($relay->getServerName())
        ->toBe('standard_test');
});
