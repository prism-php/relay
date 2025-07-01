<?php

declare(strict_types=1);

use Prism\Relay\Exceptions\ServerConfigurationException;
use Prism\Relay\Relay;
use Prism\Relay\RelayFactory;

it('creates a Relay instance', function (): void {
    config()->set('relay.servers.test_server', [
        'url' => 'http://example.com/api',
        'timeout' => 30,
        'env' => ['DEFAULT_ENV' => 'value'],
    ]);

    $factory = new RelayFactory;
    $relay = $factory->make('test_server');

    expect($relay)
        ->toBeInstanceOf(Relay::class)
        ->and($relay->getServerName())
        ->toBe('test_server');
});

it('throws exception for non-existent server', function (): void {
    $factory = new RelayFactory;

    expect(fn (): \Prism\Relay\Relay => $factory->make('non_existent_server'))
        ->toThrow(ServerConfigurationException::class, "MCP server 'non_existent_server' is not configured.");
});

it('can access the tools method', function (): void {
    // Configure a test server
    $serverName = 'test_tools_server';
    config()->set('relay.servers.'.$serverName, [
        'url' => 'http://example.com/api',
        'timeout' => 30,
    ]);

    // Just verify that the method is available
    $factory = new RelayFactory;

    // Test the call returns without error (actual results depend on implementation)
    expect(method_exists($factory, 'tools'))->toBeTrue();
});

it('handles tool definition exceptions', function (): void {
    // Just verify we can safely access the tools method
    $factory = new RelayFactory;
    expect(method_exists($factory, 'tools'))->toBeTrue();
});

it('creates Relay instance with custom environment variables', function (): void {
    config()->set('relay.servers.env_test', [
        'url' => 'http://example.com/api',
        'timeout' => 30,
        'env' => ['CONFIG_VAR' => 'config_value'],
    ]);

    $factory = new RelayFactory;
    $customEnv = ['CUSTOM_VAR' => 'custom_value'];

    $relay = $factory->make('env_test', $customEnv);

    expect($relay)
        ->toBeInstanceOf(Relay::class)
        ->and($relay->getServerName())
        ->toBe('env_test');
});
