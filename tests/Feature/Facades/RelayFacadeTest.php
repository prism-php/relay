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
        'env' => ['DEFAULT_VAR' => 'value'],
    ]);

    // Mock the actual HTTP calls that would happen under the hood
    $mock = $this->mock(RelayFactory::class);
    $mock->shouldReceive('tools')
        ->once()
        ->withArgs(fn ($server, $env = []): bool => $server === 'tools_test' && $env === [])
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

it('forwards make method with environment variables', function (): void {
    // Configure a test server
    config()->set('relay.servers.env_facade_test', [
        'url' => 'http://example.com/api',
        'timeout' => 30,
        'env' => ['CONFIG_VAR' => 'config_value'],
    ]);

    $customEnv = ['CUSTOM_VAR' => 'custom_value'];

    // Create a relay instance with custom environment
    $relay = Relay::make('env_facade_test', $customEnv);

    expect($relay)
        ->toBeInstanceOf(RelayClass::class)
        ->and($relay->getServerName())
        ->toBe('env_facade_test');
});

it('forwards tools method with environment variables', function (): void {
    // Configure a test server
    config()->set('relay.servers.env_tools_test', [
        'url' => 'http://example.com/api',
        'timeout' => 30,
        'env' => ['DEFAULT_VAR' => 'default'],
    ]);

    $customEnv = ['CUSTOM_VAR' => 'custom_value'];

    // Mock the factory to verify environment is passed
    $mock = $this->mock(RelayFactory::class);
    $mock->shouldReceive('tools')
        ->once()
        ->with('env_tools_test', $customEnv)
        ->andReturn([
            new \Prism\Prism\Tool,
        ]);

    app()->instance('relay', $mock);

    $tools = Relay::tools('env_tools_test', $customEnv);

    expect($tools)
        ->toBeArray()
        ->toHaveCount(1);
});
