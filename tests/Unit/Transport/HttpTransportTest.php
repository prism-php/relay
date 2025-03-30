<?php

declare(strict_types=1);

namespace Tests\Unit\Transport;

use Illuminate\Support\Facades\Http;
use Prism\Relay\Exceptions\TransportException;
use Prism\Relay\Transport\HttpTransport;

beforeEach(function (): void {
    $this->config = [
        'url' => 'http://localhost:8000/api',
        'api_key' => 'test-api-key',
        'timeout' => 30,
    ];

    Http::preventStrayRequests();
});

it('sends request successfully', function (): void {
    Http::fake([
        'localhost:8000/api' => Http::response([
            'jsonrpc' => '2.0',
            'id' => '1',
            'result' => ['success' => true],
        ]),
    ]);

    $transport = new HttpTransport($this->config);
    $transport->start();
    $result = $transport->sendRequest('test/method', ['param' => 'value']);

    Http::assertSent(fn ($request): bool => $request->url() === 'http://localhost:8000/api' &&
           $request->method() === 'POST' &&
           $request->hasHeader('Authorization', 'Bearer test-api-key') &&
           $request->body() === json_encode([
               'jsonrpc' => '2.0',
               'id' => '1',
               'method' => 'test/method',
               'params' => ['param' => 'value'],
           ]));

    expect($result)->toBe(['success' => true]);
});

it('works without api key', function (): void {
    $configWithoutApiKey = $this->config;
    unset($configWithoutApiKey['api_key']);

    Http::fake([
        'localhost:8000/api' => Http::response([
            'jsonrpc' => '2.0',
            'id' => '1',
            'result' => ['success' => true],
        ]),
    ]);

    $transport = new HttpTransport($configWithoutApiKey);
    $transport->sendRequest('test/method');

    Http::assertSent(fn ($request): bool => $request->url() === 'http://localhost:8000/api' &&
           ! $request->hasHeader('Authorization'));
});

it('works with null api key', function (): void {
    $configWithNullApiKey = $this->config;
    $configWithNullApiKey['api_key'] = null;

    Http::fake([
        'localhost:8000/api' => Http::response([
            'jsonrpc' => '2.0',
            'id' => '1',
            'result' => ['success' => true],
        ]),
    ]);

    $transport = new HttpTransport($configWithNullApiKey);
    $transport->sendRequest('test/method');

    Http::assertSent(fn ($request): bool => $request->url() === 'http://localhost:8000/api' &&
           ! $request->hasHeader('Authorization'));
});

it('works with empty api key', function (): void {
    $configWithEmptyApiKey = $this->config;
    $configWithEmptyApiKey['api_key'] = '';

    Http::fake([
        'localhost:8000/api' => Http::response([
            'jsonrpc' => '2.0',
            'id' => '1',
            'result' => ['success' => true],
        ]),
    ]);

    $transport = new HttpTransport($configWithEmptyApiKey);
    $transport->sendRequest('test/method');

    Http::assertSent(fn ($request): bool => $request->url() === 'http://localhost:8000/api' &&
           ! $request->hasHeader('Authorization', 'Bearer '));
});

it('uses custom timeout', function (): void {
    $configWithCustomTimeout = $this->config;
    $configWithCustomTimeout['timeout'] = 60;

    Http::fake([
        'localhost:8000/api' => Http::response([
            'jsonrpc' => '2.0',
            'id' => '1',
            'result' => ['success' => true],
        ]),
    ]);

    $transport = new HttpTransport($configWithCustomTimeout);
    $result = $transport->sendRequest('test/method');

    expect($result)->toBe(['success' => true]);
});

it('uses default timeout when not provided', function (): void {
    $configWithoutTimeout = $this->config;
    unset($configWithoutTimeout['timeout']);

    Http::fake([
        'localhost:8000/api' => Http::response([
            'jsonrpc' => '2.0',
            'id' => '1',
            'result' => ['success' => true],
        ]),
    ]);

    $transport = new HttpTransport($configWithoutTimeout);
    $result = $transport->sendRequest('test/method');

    expect($result)->toBe(['success' => true]);
});

it('throws exception with http error', function (): void {
    Http::fake([
        'localhost:8000/api' => Http::response('Server error', 500),
    ]);

    $transport = new HttpTransport($this->config);

    expect(fn (): array => $transport->sendRequest('test/method'))
        ->toThrow(TransportException::class, 'HTTP request failed with status code: 500');
});

it('throws exception with invalid jsonrpc response', function (): void {
    Http::fake([
        'localhost:8000/api' => Http::response([
            'id' => '1',
            'result' => ['success' => true],
        ]),
    ]);

    $transport = new HttpTransport($this->config);

    expect(fn (): array => $transport->sendRequest('test/method'))
        ->toThrow(TransportException::class, 'Invalid JSON-RPC 2.0 response received');
});

it('throws exception with invalid jsonrpc version', function (): void {
    Http::fake([
        'localhost:8000/api' => Http::response([
            'jsonrpc' => '1.0',
            'id' => '1',
            'result' => ['success' => true],
        ]),
    ]);

    $transport = new HttpTransport($this->config);

    expect(fn (): array => $transport->sendRequest('test/method'))
        ->toThrow(TransportException::class, 'Invalid JSON-RPC 2.0 response received');
});

it('throws exception with missing id', function (): void {
    Http::fake([
        'localhost:8000/api' => Http::response([
            'jsonrpc' => '2.0',
            'result' => ['success' => true],
        ]),
    ]);

    $transport = new HttpTransport($this->config);

    expect(fn (): array => $transport->sendRequest('test/method'))
        ->toThrow(TransportException::class, 'Invalid JSON-RPC 2.0 response received');
});

it('throws exception with mismatched id', function (): void {
    Http::fake([
        'localhost:8000/api' => Http::response([
            'jsonrpc' => '2.0',
            'id' => '999',
            'result' => ['success' => true],
        ]),
    ]);

    $transport = new HttpTransport($this->config);

    expect(fn (): array => $transport->sendRequest('test/method'))
        ->toThrow(TransportException::class, 'Invalid JSON-RPC 2.0 response received');
});

it('throws exception with jsonrpc error', function (): void {
    Http::fake([
        'localhost:8000/api' => Http::response([
            'jsonrpc' => '2.0',
            'id' => '1',
            'error' => [
                'code' => -32600,
                'message' => 'Invalid request',
            ],
        ]),
    ]);

    $transport = new HttpTransport($this->config);

    expect(fn (): array => $transport->sendRequest('test/method'))
        ->toThrow(TransportException::class, 'JSON-RPC error: Invalid request (code: -32600)');
});

it('throws exception with jsonrpc error and data', function (): void {
    Http::fake([
        'localhost:8000/api' => Http::response([
            'jsonrpc' => '2.0',
            'id' => '1',
            'error' => [
                'code' => -32602,
                'message' => 'Invalid params',
                'data' => ['field' => 'param', 'reason' => 'required'],
            ],
        ]),
    ]);

    $transport = new HttpTransport($this->config);

    expect(fn (): array => $transport->sendRequest('test/method'))
        ->toThrow(TransportException::class, 'JSON-RPC error: Invalid params (code: -32602) Details: {"field":"param","reason":"required"}');
});

it('throws exception with network error', function (): void {
    Http::fake(function (): void {
        throw new \Exception('Network error');
    });

    $transport = new HttpTransport($this->config);

    expect(fn (): array => $transport->sendRequest('test/method'))
        ->toThrow(TransportException::class, 'Failed to send request to MCP server: Network error');
});

it('handles empty result', function (): void {
    Http::fake([
        'localhost:8000/api' => Http::response([
            'jsonrpc' => '2.0',
            'id' => '1',
        ]),
    ]);

    $transport = new HttpTransport($this->config);
    $result = $transport->sendRequest('test/method');

    expect($result)->toBe([]);
});

it('handles multiple requests', function (): void {
    Http::fake([
        'localhost:8000/api' => Http::sequence()
            ->push([
                'jsonrpc' => '2.0',
                'id' => '1',
                'result' => ['request' => 1],
            ])
            ->push([
                'jsonrpc' => '2.0',
                'id' => '2',
                'result' => ['request' => 2],
            ]),
    ]);

    $transport = new HttpTransport($this->config);

    $result1 = $transport->sendRequest('test/method1');
    expect($result1)->toBe(['request' => 1]);

    $result2 = $transport->sendRequest('test/method2');
    expect($result2)->toBe(['request' => 2]);

    Http::assertSentCount(2);
});

it('can close and start', function (): void {
    $transport = new HttpTransport($this->config);

    $transport->start();
    $transport->close();

    expect(true)->toBeTrue();
});
