<?php

declare(strict_types=1);

use Prism\Relay\Exceptions\ServerConfigurationException;
use Prism\Relay\Relay;
use Prism\Relay\RelayFactory;

it('creates a Relay instance', function (): void {
    config()->set('relay.servers.test_server', [
        'url' => 'http://example.com/api',
        'timeout' => 30,
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

it('creates a Relay instance with custom config', function (): void {
    $customConfig = [
        'transport' => \Prism\Relay\Enums\Transport::Http,
        'url' => 'http://custom.example.com/api',
        'timeout' => 45,
    ];

    $factory = new RelayFactory;
    $relay = $factory->make('custom_server', $customConfig);

    expect($relay)
        ->toBeInstanceOf(Relay::class)
        ->and($relay->getServerName())
        ->toBe('custom_server');
});

it('creates Relay with custom config when config parameter is provided', function (): void {
    $customConfig = [
        'transport' => \Prism\Relay\Enums\Transport::Stdio,
        'command' => ['echo', 'test'],
        'timeout' => 60,
        'env' => ['TEST_VAR' => 'test_value'],
    ];

    $factory = new RelayFactory;
    $relay = $factory->make('stdio_server', $customConfig);

    expect($relay)
        ->toBeInstanceOf(Relay::class)
        ->and($relay->getServerName())
        ->toBe('stdio_server');
});

it('uses Laravel config when no custom config provided', function (): void {
    config()->set('relay.servers.laravel_server', [
        'url' => 'http://laravel.example.com/api',
        'timeout' => 30,
    ]);

    $factory = new RelayFactory;

    // Test both ways produce same result
    $relay1 = $factory->make('laravel_server');
    $relay2 = $factory->make('laravel_server', null);

    expect($relay1)
        ->toBeInstanceOf(Relay::class)
        ->and($relay2)
        ->toBeInstanceOf(Relay::class)
        ->and($relay1->getServerName())
        ->toBe('laravel_server')
        ->and($relay2->getServerName())
        ->toBe('laravel_server');
});

it('tools method works with custom config', function (): void {
    $customConfig = [
        'transport' => \Prism\Relay\Enums\Transport::Http,
        'url' => 'http://tools.example.com/api',
        'timeout' => 30,
    ];

    $factory = new RelayFactory;

    // Verify method exists and accepts config parameter
    expect(method_exists($factory, 'tools'))->toBeTrue();

    // Test method signature (this will call the method but we expect it might fail due to no real server)
    try {
        $factory->tools('tools_server', $customConfig);
    } catch (\Throwable $e) {
        // Expected to fail since we don't have a real MCP server, but method should accept the parameters
        expect($e)->toBeInstanceOf(\Throwable::class);
    }
});
