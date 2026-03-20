<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Prism\Relay\Enums\Transport;
use Prism\Relay\Exceptions\ServerConfigurationException;
use Prism\Relay\Relay;

beforeEach(function (): void {
    $this->serverName = 'github';
    $this->httpConfig = [
        'transport' => Transport::Http,
        'url' => 'http://example.com/api',
        'timeout' => 30,
    ];
    $this->stdioConfig = [
        'transport' => Transport::Stdio,
        'command' => ['echo', 'test'],
        'env' => [],
        'timeout' => 30,
    ];

    config()->set('relay.servers.'.$this->serverName, $this->httpConfig);
    config()->set('relay.cache_duration', 60);
    Cache::flush();
});

it('withToken returns the same Relay instance', function (): void {
    $relay = new Relay($this->serverName);
    $result = $relay->withToken('my-oauth-token');

    expect($result)->toBe($relay);
});

it('withToken on Stdio-configured Relay throws ServerConfigurationException', function (): void {
    config()->set('relay.servers.'.$this->serverName, $this->stdioConfig);

    $relay = new Relay($this->serverName);

    expect(fn (): Relay => $relay->withToken('my-oauth-token'))
        ->toThrow(ServerConfigurationException::class, 'OAuth access tokens are only supported with HTTP transport');
});

it('withToken injects token into HTTP requests when tools() is called', function (): void {
    Http::fake([
        'http://example.com/api' => Http::sequence()
            ->push(['jsonrpc' => '2.0', 'id' => '1', 'result' => ['protocolVersion' => '2025-03-26']])
            ->push('', 202)
            ->push([
                'jsonrpc' => '2.0',
                'id' => '2',
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

    $relay = new Relay($this->serverName);
    $relay->withToken('my-oauth-token')->tools();

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer my-oauth-token'));
});

it('cache key includes token hash when token is set', function (): void {
    $token = 'my-oauth-token';
    $expectedHash = substr(hash('sha256', $token), 0, 16);

    $relay = new class($this->serverName) extends Relay
    {
        public function getCacheKey(): string
        {
            return $this->buildCacheKey();
        }
    };

    $relay->withToken($token);

    expect($relay->getCacheKey())->toBe("relay-tools-definitions-{$this->serverName}-{$expectedHash}");
});

it('cache key does not include hash when no token is set', function (): void {
    $relay = new class($this->serverName) extends Relay
    {
        public function getCacheKey(): string
        {
            return $this->buildCacheKey();
        }
    };

    expect($relay->getCacheKey())->toBe("relay-tools-definitions-{$this->serverName}");
});

it('two different tokens produce two different cache keys', function (): void {
    $makeRelay = fn (): Relay => new class($this->serverName) extends Relay
    {
        public function getCacheKey(): string
        {
            return $this->buildCacheKey();
        }
    };

    $relay1 = $makeRelay();
    $relay1->withToken('token-alpha');

    $relay2 = $makeRelay();
    $relay2->withToken('token-beta');

    expect($relay1->getCacheKey())->not->toBe($relay2->getCacheKey());
});
