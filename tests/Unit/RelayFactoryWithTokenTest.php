<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Relay\Enums\Transport;
use Prism\Relay\Relay;
use Prism\Relay\RelayBuilder;
use Prism\Relay\RelayFactory;

beforeEach(function (): void {
    config()->set('relay.servers.github', [
        'transport' => Transport::Http,
        'url' => 'http://example.com/api',
        'timeout' => 30,
    ]);
    config()->set('relay.cache_duration', 0);
});

it('withToken returns a RelayBuilder instance', function (): void {
    $factory = new RelayFactory;
    $builder = $factory->withToken('my-oauth-token');

    expect($builder)->toBeInstanceOf(RelayBuilder::class);
});

it('RelayBuilder::make returns a Relay instance with the token applied', function (): void {
    $factory = new RelayFactory;
    $relay = $factory->withToken('my-oauth-token')->make('github');

    expect($relay)->toBeInstanceOf(Relay::class)
        ->and($relay->getServerName())->toBe('github');
});

it('RelayBuilder::tools calls Relay::tools with the token injected', function (): void {
    Http::fake([
        'http://example.com/api' => Http::response([
            'jsonrpc' => '2.0',
            'id' => '1',
            'result' => [
                'tools' => [
                    [
                        'name' => 'test_tool',
                        'description' => 'A test tool',
                        'inputSchema' => ['type' => 'object', 'properties' => []],
                    ],
                ],
            ],
        ]),
    ]);

    $factory = new RelayFactory;
    $factory->withToken('my-oauth-token')->tools('github');

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer my-oauth-token'));
});

it('calling withToken twice returns independent RelayBuilder instances', function (): void {
    $factory = new RelayFactory;

    $builder1 = $factory->withToken('token-one');
    $builder2 = $factory->withToken('token-two');

    expect($builder1)->not->toBe($builder2);
});
