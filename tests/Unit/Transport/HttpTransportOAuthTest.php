<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Relay\Exceptions\AuthorizationException;
use Prism\Relay\Exceptions\TransportException;
use Prism\Relay\Transport\HttpTransport;
use Tests\TestDoubles\HttpTransportFake;

it('sends access_token as Bearer Authorization header', function (): void {
    Http::fake([
        'http://example.com/api' => Http::sequence()
            ->push(['jsonrpc' => '2.0', 'id' => '1', 'result' => ['protocolVersion' => '2025-03-26']])
            ->push('', 202)
            ->push(['jsonrpc' => '2.0', 'id' => '2', 'result' => ['status' => 'success']]),
    ]);

    $transport = new HttpTransport([
        'url' => 'http://example.com/api',
        'access_token' => 'my-oauth-token',
        'timeout' => 30,
    ]);

    $transport->sendRequest('test/method');

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer my-oauth-token'));
});

it('uses access_token over api_key when both are present', function (): void {
    Http::fake([
        'http://example.com/api' => Http::sequence()
            ->push(['jsonrpc' => '2.0', 'id' => '1', 'result' => ['protocolVersion' => '2025-03-26']])
            ->push('', 202)
            ->push(['jsonrpc' => '2.0', 'id' => '2', 'result' => ['status' => 'success']]),
    ]);

    $transport = new HttpTransport([
        'url' => 'http://example.com/api',
        'access_token' => 'runtime-oauth-token',
        'api_key' => 'static-api-key',
        'timeout' => 30,
    ]);

    $transport->sendRequest('test/method');

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer runtime-oauth-token'));
});

it('falls back to api_key when no access_token is set', function (): void {
    Http::fake([
        'http://example.com/api' => Http::sequence()
            ->push(['jsonrpc' => '2.0', 'id' => '1', 'result' => ['protocolVersion' => '2025-03-26']])
            ->push('', 202)
            ->push(['jsonrpc' => '2.0', 'id' => '2', 'result' => ['status' => 'success']]),
    ]);

    $transport = new HttpTransport([
        'url' => 'http://example.com/api',
        'api_key' => 'static-api-key',
        'timeout' => 30,
    ]);

    $transport->sendRequest('test/method');

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer static-api-key'));
});

it('throws AuthorizationException on HTTP 401 response', function (): void {
    $transport = new HttpTransportFake([
        'url' => 'http://example.com/api',
        'access_token' => 'expired-token',
        'timeout' => 30,
    ]);

    $transport->returnUnauthorized();

    expect(fn (): array => $transport->sendRequest('tools/list'))
        ->toThrow(AuthorizationException::class, 'MCP server returned 401 Unauthorized');
});

it('throws TransportException on HTTP 403 response, not AuthorizationException', function (): void {
    $transport = new HttpTransportFake([
        'url' => 'http://example.com/api',
        'access_token' => 'some-token',
        'timeout' => 30,
    ]);

    $transport->failHttpRequest(403);

    expect(fn (): array => $transport->sendRequest('tools/list'))
        ->toThrow(TransportException::class)
        ->not->toThrow(AuthorizationException::class);
});
