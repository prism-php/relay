<?php

declare(strict_types=1);

use Prism\Relay\Exceptions\TransportException;
use Prism\Relay\Transport\HttpSseTransport;
use Tests\TestDoubles\HttpSseTransportFake;

it('can be instantiated', function (): void {
    $transport = new HttpSseTransport([
        'url' => 'http://example.com/sse',
        'timeout' => 30,
    ]);

    expect($transport)->toBeInstanceOf(HttpSseTransport::class);
});

it('connects to SSE and initializes on start', function (): void {
    $transport = new HttpSseTransportFake([
        'url' => 'http://example.com/sse',
        'timeout' => 30,
    ]);

    $transport->start();

    expect($transport->getSessionIdValue())->toBe('fake-session-123')
        ->and($transport->getMessageEndpointValue())->toBe('http://example.com/messages/?session_id=fake-session-123');
});

it('does not re-initialize when already started', function (): void {
    $transport = new HttpSseTransportFake([
        'url' => 'http://example.com/sse',
        'timeout' => 30,
    ]);

    $transport->start();
    $transport->start(); // Should not throw or re-connect

    expect($transport->getSessionIdValue())->toBe('fake-session-123');
});

it('captures session ID from endpoint event', function (): void {
    $transport = new HttpSseTransportFake([
        'url' => 'http://example.com/sse',
        'timeout' => 30,
    ]);

    $transport->withFakeSessionId('my-custom-session-456');
    $transport->start();

    expect($transport->getSessionIdValue())->toBe('my-custom-session-456')
        ->and($transport->getMessageEndpointValue())->toContain('session_id=my-custom-session-456');
});

it('sends requests and receives responses via SSE', function (): void {
    $transport = new HttpSseTransportFake([
        'url' => 'http://example.com/sse',
        'timeout' => 30,
    ]);

    $transport->start();

    $result = $transport->sendRequest('tools/list');

    expect($result)->toBeArray()
        ->and($result)->toHaveKey('tools');
});

it('handles tools/call requests', function (): void {
    $transport = new HttpSseTransportFake([
        'url' => 'http://example.com/sse',
        'timeout' => 30,
    ]);

    $transport->start();

    $result = $transport->sendRequest('tools/call', [
        'name' => 'test_tool',
        'arguments' => ['param1' => 'value1'],
    ]);

    expect($result)->toBeArray()
        ->and($result)->toHaveKey('content');
});

it('sets custom response for specific methods', function (): void {
    $transport = new HttpSseTransportFake([
        'url' => 'http://example.com/sse',
        'timeout' => 30,
    ]);

    $transport->setResponse('tools/list', [
        'tools' => [
            [
                'name' => 'custom_tool',
                'description' => 'A custom tool',
            ],
        ],
    ]);

    $transport->start();

    $result = $transport->sendRequest('tools/list');

    expect($result)->toHaveKey('tools')
        ->and($result['tools'][0]['name'])->toBe('custom_tool');
});

it('throws exception when SSE connection fails', function (): void {
    $transport = new HttpSseTransportFake([
        'url' => 'http://example.com/sse',
        'timeout' => 30,
    ]);

    $transport->shouldFailConnect();

    expect(fn () => $transport->start())
        ->toThrow(TransportException::class, 'Failed to connect to SSE endpoint');
});

it('throws exception when POST fails', function (): void {
    $transport = new HttpSseTransportFake([
        'url' => 'http://example.com/sse',
        'timeout' => 30,
    ]);

    $transport->shouldFailPost();

    expect(fn () => $transport->start())
        ->toThrow(TransportException::class, 'Failed to post message to MCP server');
});

it('throws exception on JSON-RPC error', function (): void {
    $transport = new HttpSseTransportFake([
        'url' => 'http://example.com/sse',
        'timeout' => 30,
    ]);

    $transport->start();
    $transport->shouldReturnError('Tool not found', 404);

    expect(fn (): array => $transport->sendRequest('tools/call', ['name' => 'nonexistent']))
        ->toThrow(TransportException::class, 'JSON-RPC error: Tool not found (code: 404)');
});

it('auto-starts when sending request without explicit start', function (): void {
    $transport = new HttpSseTransportFake([
        'url' => 'http://example.com/sse',
        'timeout' => 30,
    ]);

    // Don't call start() explicitly
    $result = $transport->sendRequest('tools/list');

    expect($result)->toBeArray()
        ->and($result)->toHaveKey('tools');
});

it('closes cleanly', function (): void {
    $transport = new HttpSseTransportFake([
        'url' => 'http://example.com/sse',
        'timeout' => 30,
    ]);

    $transport->start();
    $transport->close();

    expect($transport->getSessionIdValue())->toBeNull()
        ->and($transport->getMessageEndpointValue())->toBeNull();
});

it('can reconnect after close', function (): void {
    $transport = new HttpSseTransportFake([
        'url' => 'http://example.com/sse',
        'timeout' => 30,
    ]);

    $transport->start();
    $transport->close();

    // Should be able to start again
    $transport->start();

    expect($transport->getSessionIdValue())->toBe('fake-session-123');
});

it('uses default timeout when not specified', function (): void {
    $transport = new HttpSseTransportFake([
        'url' => 'http://example.com/sse',
    ]);

    $transport->start();
    $result = $transport->sendRequest('tools/list');

    expect($result)->toBeArray();
});

it('handles multiple sequential requests', function (): void {
    $transport = new HttpSseTransportFake([
        'url' => 'http://example.com/sse',
        'timeout' => 30,
    ]);

    $transport->start();

    $result1 = $transport->sendRequest('tools/list');
    expect($result1)->toHaveKey('tools');

    $result2 = $transport->sendRequest('tools/call', [
        'name' => 'test_tool',
        'arguments' => ['param1' => 'value1'],
    ]);
    expect($result2)->toHaveKey('content');

    $result3 = $transport->sendRequest('tools/list');
    expect($result3)->toHaveKey('tools');
});

it('parses endpoint data correctly', function (): void {
    $transport = new HttpSseTransport([
        'url' => 'http://example.com/sse',
        'timeout' => 30,
    ]);

    $reflection = new ReflectionClass($transport);
    $method = $reflection->getMethod('parseEndpointData');
    $method->setAccessible(true);

    $method->invoke($transport, '/messages/?session_id=abc123def');

    $sessionIdProp = $reflection->getProperty('sessionId');
    $sessionIdProp->setAccessible(true);

    $endpointProp = $reflection->getProperty('messageEndpoint');
    $endpointProp->setAccessible(true);

    expect($sessionIdProp->getValue($transport))->toBe('abc123def')
        ->and($endpointProp->getValue($transport))->toBe('http://example.com/messages/?session_id=abc123def');
});

it('throws exception for invalid endpoint data', function (): void {
    $transport = new HttpSseTransport([
        'url' => 'http://example.com/sse',
        'timeout' => 30,
    ]);

    $reflection = new ReflectionClass($transport);
    $method = $reflection->getMethod('parseEndpointData');
    $method->setAccessible(true);

    expect(fn (): mixed => $method->invoke($transport, '/invalid/endpoint'))
        ->toThrow(TransportException::class, 'Invalid endpoint data');
});

it('processes SSE events correctly', function (): void {
    $transport = new HttpSseTransport([
        'url' => 'http://example.com/sse',
        'timeout' => 30,
    ]);

    $reflection = new ReflectionClass($transport);

    $requestIdProp = $reflection->getProperty('requestId');
    $requestIdProp->setAccessible(true);
    $requestIdProp->setValue($transport, 5);

    $method = $reflection->getMethod('processSSEEvent');
    $method->setAccessible(true);

    // Valid message event
    $result = $method->invoke($transport, 'message', '{"jsonrpc":"2.0","id":"5","result":{"status":"ok"}}');
    expect($result)->toBe(['status' => 'ok']);

    // Non-message event should be ignored
    $result = $method->invoke($transport, 'endpoint', '{"some":"data"}');
    expect($result)->toBeNull();

    // Wrong request ID should be ignored
    $result = $method->invoke($transport, 'message', '{"jsonrpc":"2.0","id":"99","result":{"status":"ok"}}');
    expect($result)->toBeNull();

    // Invalid JSON should be ignored
    $result = $method->invoke($transport, 'message', 'not-json');
    expect($result)->toBeNull();
});

it('handles JSON-RPC error in SSE event', function (): void {
    $transport = new HttpSseTransport([
        'url' => 'http://example.com/sse',
        'timeout' => 30,
    ]);

    $reflection = new ReflectionClass($transport);

    $requestIdProp = $reflection->getProperty('requestId');
    $requestIdProp->setAccessible(true);
    $requestIdProp->setValue($transport, 1);

    $method = $reflection->getMethod('processSSEEvent');
    $method->setAccessible(true);

    expect(fn (): mixed => $method->invoke(
        $transport,
        'message',
        '{"jsonrpc":"2.0","id":"1","error":{"code":500,"message":"Internal error"}}'
    ))->toThrow(TransportException::class, 'JSON-RPC error: Internal error (code: 500)');
});
