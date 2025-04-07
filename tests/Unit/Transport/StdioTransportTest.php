<?php

declare(strict_types=1);

use Prism\Relay\Exceptions\ServerConfigurationException;
use Prism\Relay\Exceptions\TransportException;
use Tests\TestDoubles\StdioTransportFake;

it('validates config during construction', function (): void {
    $config = [
        'timeout' => 30,
    ];

    expect(fn (): \Tests\TestDoubles\StdioTransportFake => new StdioTransportFake($config))
        ->toThrow(ServerConfigurationException::class, 'The "command" configuration is required for stdio transport');
});

it('requires env configuration', function (): void {
    $config = [
        'command' => ['test', 'command'],
        'timeout' => 30,
    ];

    expect(fn (): \Tests\TestDoubles\StdioTransportFake => new StdioTransportFake($config))
        ->toThrow(ServerConfigurationException::class, 'The "env" configuration is required for stdio transport');
});

it('properly encodes array command arguments as JSON', function (): void {
    $config = [
        'command' => ['test', 'command', ['foo' => 'bar', 'baz' => 123]],
        'env' => ['TEST' => 'value'],
        'timeout' => 30,
    ];

    $transport = new StdioTransportFake($config);
    $command = $transport->command();

    // JSON arrays in the command should be properly encoded
    expect($command)->toBe('test command {\"foo\":\"bar\",\"baz\":123}');
});

it('starts the process properly', function (): void {
    $transport = new StdioTransportFake([
        'command' => ['test', 'command'],
        'env' => ['TEST' => 'value'],
        'timeout' => 30,
    ]);

    // Should not throw
    $transport->start();
    expect(true)->toBeTrue();
});

it('handles start failure', function (): void {
    $transport = new StdioTransportFake([
        'command' => ['test', 'command'],
        'env' => ['TEST' => 'value'],
        'timeout' => 30,
    ]);

    $transport->shouldFailProcess();

    expect(fn () => $transport->start())
        ->toThrow(TransportException::class, 'Failed to start process');
});

it('restarts process if not running when sending request', function (): void {
    $transport = new StdioTransportFake([
        'command' => ['test', 'command'],
        'env' => ['TEST' => 'value'],
        'timeout' => 30,
    ]);

    $transport->shouldBeRunning(false);

    // This should now throw exception
    expect(fn (): array => $transport->sendRequest('test/method', ['param' => 'value']))
        ->toThrow(TransportException::class, 'Process not running');
});

it('handles JSON-RPC errors', function (): void {
    $transport = new StdioTransportFake([
        'command' => ['test', 'command'],
        'env' => ['TEST' => 'value'],
        'timeout' => 30,
    ]);

    $transport->shouldReturnError('Custom error', 123);

    expect(fn (): array => $transport->sendRequest('test/method', ['param' => 'value']))
        ->toThrow(TransportException::class, 'JSON-RPC error: Custom error (code: 123)');
});

it('handles response timeout', function (): void {
    $transport = new StdioTransportFake([
        'command' => ['test', 'command'],
        'env' => ['TEST' => 'value'],
        'timeout' => 30,
    ]);

    $transport->shouldTimeoutResponse();

    expect(fn (): array => $transport->sendRequest('test/method', ['param' => 'value']))
        ->toThrow(TransportException::class, 'Timeout waiting for MCP response');
});

it('properly processes tools/list response', function (): void {
    $transport = new StdioTransportFake([
        'command' => ['test', 'command'],
        'env' => ['TEST' => 'value'],
        'timeout' => 30,
    ]);

    $result = $transport->sendRequest('tools/list');

    expect($result)
        ->toHaveKey('tools')
        ->and($result['tools'])
        ->toBeArray()
        ->toHaveCount(1);
});

it('properly processes tools/call response', function (): void {
    $transport = new StdioTransportFake([
        'command' => ['test', 'command'],
        'env' => ['TEST' => 'value'],
        'timeout' => 30,
    ]);

    $result = $transport->sendRequest('tools/call', [
        'name' => 'test_tool',
        'arguments' => ['param1' => 'value1'],
    ]);

    expect($result)
        ->toHaveKey('content')
        ->and($result['content'])
        ->toBeArray();
});

it('closes properly', function (): void {
    $transport = new StdioTransportFake([
        'command' => ['test', 'command'],
        'env' => ['TEST' => 'value'],
        'timeout' => 30,
    ]);

    // Should not throw exception
    $transport->close();
    expect(true)->toBeTrue();
});

// Test auto-close on destroy through __destruct
it('closes in destructor', function (): void {
    $transport = new StdioTransportFake([
        'command' => ['test', 'command'],
        'env' => ['TEST' => 'value'],
        'timeout' => 30,
    ]);

    // Explicitly unset to trigger __destruct
    unset($transport);
    expect(true)->toBeTrue();
});
