<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Relay\Exceptions\TransportException;
use Prism\Relay\Transport\HttpTransport;

it('starts by sending initialize and initialized notification', function (): void {
    Http::fake([
        'http://example.com/api' => Http::sequence()
            ->push([
                'jsonrpc' => '2.0',
                'id' => '1',
                'result' => ['protocolVersion' => '2025-03-26'],
            ], 200, ['Mcp-Session-Id' => 'session-123'])
            ->push('', 202),
    ]);

    $transport = new HttpTransport([
        'url' => 'http://example.com/api',
    ]);

    $transport->start();

    Http::assertSentCount(2);
    Http::assertSent(fn ($request): bool => (json_decode((string) $request->body(), true)['method'] ?? null) === 'initialize');
    Http::assertSent(fn ($request): bool => (json_decode((string) $request->body(), true)['method'] ?? null) === 'notifications/initialized');
});

it('does not initialize twice when start is called repeatedly', function (): void {
    Http::fake([
        'http://example.com/api' => Http::sequence()
            ->push([
                'jsonrpc' => '2.0',
                'id' => '1',
                'result' => ['protocolVersion' => '2025-03-26'],
            ], 200)
            ->push('', 202),
    ]);

    $transport = new HttpTransport([
        'url' => 'http://example.com/api',
    ]);

    $transport->start();
    $transport->start();

    Http::assertSentCount(2);
});

it('sends initialize flow and request payload with object params', function (): void {
    Http::fake([
        'http://example.com/api' => Http::sequence()
            ->push([
                'jsonrpc' => '2.0',
                'id' => '1',
                'result' => ['protocolVersion' => '2025-03-26'],
            ], 200, ['Mcp-Session-Id' => 'session-123'])
            ->push('', 202)
            ->push([
                'jsonrpc' => '2.0',
                'id' => '2',
                'result' => ['status' => 'success'],
            ]),
    ]);

    $transport = new HttpTransport([
        'url' => 'http://example.com/api',
    ]);

    $result = $transport->sendRequest('tools/list');

    expect($result)->toBe(['status' => 'success']);
    Http::assertSent(function ($request): bool {
        $body = json_decode((string) $request->body());

        return isset($body->method, $body->params)
            && $body->method === 'tools/list'
            && is_object($body->params)
            && get_object_vars($body->params) === [];
    });
});

it('sets accept header for json and sse', function (): void {
    $transport = new HttpTransport([
        'url' => 'http://example.com/api',
    ]);

    Http::fake([
        'http://example.com/api' => Http::sequence()
            ->push([
                'jsonrpc' => '2.0',
                'id' => '1',
                'result' => ['protocolVersion' => '2025-03-26'],
            ])
            ->push('', 202)
            ->push([
                'jsonrpc' => '2.0',
                'id' => '2',
                'result' => ['status' => 'success'],
            ]),
    ]);

    $transport->sendRequest('test/method');

    Http::assertSent(fn ($request): bool => $request->hasHeader('Accept', 'application/json, text/event-stream'));
});

it('attaches session id header after initialize', function (): void {
    $requests = [];

    Http::fake(function ($request) use (&$requests) {
        $requests[] = $request;

        $method = json_decode((string) $request->body(), true)['method'] ?? null;

        if ($method === 'initialize') {
            return Http::response([
                'jsonrpc' => '2.0',
                'id' => '1',
                'result' => ['protocolVersion' => '2025-03-26'],
            ], 200, ['Mcp-Session-Id' => 'session-123']);
        }

        if ($method === 'notifications/initialized') {
            return Http::response('', 202);
        }

        return Http::response([
            'jsonrpc' => '2.0',
            'id' => '2',
            'result' => ['status' => 'success'],
        ]);
    });

    $transport = new HttpTransport(['url' => 'http://example.com/api']);
    $transport->sendRequest('tools/list');

    expect($requests)->toHaveCount(3)
        ->and($requests[2]->hasHeader('Mcp-Session-Id', 'session-123'))->toBeTrue();
});

it('parses json-rpc payload from sse response', function (): void {
    $transport = new HttpTransport(['url' => 'http://example.com/api']);

    Http::fake([
        'http://example.com/api' => Http::sequence()
            ->push([
                'jsonrpc' => '2.0',
                'id' => '1',
                'result' => ['protocolVersion' => '2025-03-26'],
            ])
            ->push('', 202)
            ->push(
                "event: message\n".
                "data: {\"jsonrpc\":\"2.0\",\"id\":\"2\",\"result\":{\"status\":\"success\"}}\n\n",
                200,
                ['Content-Type' => 'text/event-stream']
            ),
    ]);

    $result = $transport->sendRequest('tools/list');

    expect($result)->toBe(['status' => 'success']);
});

it('throws on invalid sse payload', function (): void {
    $transport = new HttpTransport(['url' => 'http://example.com/api']);

    Http::fake([
        'http://example.com/api' => Http::sequence()
            ->push([
                'jsonrpc' => '2.0',
                'id' => '1',
                'result' => ['protocolVersion' => '2025-03-26'],
            ])
            ->push('', 202)
            ->push("event: message\ndata: not-json\n\n", 200, ['Content-Type' => 'text/event-stream']),
    ]);

    $transport->sendRequest('tools/list');
})->throws(TransportException::class, 'No JSON-RPC message found in SSE response');

it('supports api key and custom headers', function (): void {
    $transport = new HttpTransport([
        'url' => 'http://example.com/api',
        'api_key' => 'test-key',
        'headers' => [
            'User-Agent' => 'prism-php-relay/1.0',
        ],
    ]);

    Http::fake([
        'http://example.com/api' => Http::sequence()
            ->push([
                'jsonrpc' => '2.0',
                'id' => '1',
                'result' => ['protocolVersion' => '2025-03-26'],
            ])
            ->push('', 202)
            ->push([
                'jsonrpc' => '2.0',
                'id' => '2',
                'result' => ['status' => 'success'],
            ]),
    ]);

    $transport->sendRequest('test/method');

    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
    Http::assertSent(fn ($request): bool => $request->hasHeader('User-Agent', 'prism-php-relay/1.0'));
});
