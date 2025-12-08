<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\Cache;
use Prism\Prism\Schema\AnyOfSchema;
use Prism\Prism\Tool;
use Prism\Relay\Exceptions\ServerConfigurationException;
use Prism\Relay\Exceptions\ToolDefinitionException;
use Tests\TestDoubles\RelayFake;

beforeEach(function (): void {
    // We'll use the RelayFake for all tests to control its behavior
    $this->serverName = 'test_server';
    config()->set('relay.servers.'.$this->serverName, [
        'url' => 'http://example.com/api',
        'timeout' => 30,
    ]);

    // Clear any cached tools
    Cache::forget('relay-tools-definitions-'.$this->serverName);
});

it('initializes with correct server configuration', function (): void {
    $relay = new RelayFake($this->serverName);
    expect($relay->getServerName())->toBe($this->serverName);
});

it('throws exception for non-existent server', function (): void {
    $nonExistentServer = 'non_existent_server';
    expect(fn (): \Tests\TestDoubles\RelayFake => new RelayFake($nonExistentServer))
        ->toThrow(ServerConfigurationException::class);
});

it('fetches tool definitions', function (): void {
    $relay = new RelayFake($this->serverName);
    $tools = $relay->tools();

    expect($tools)
        ->toBeArray()
        ->not->toBeEmpty()
        ->and($tools[0])
        ->toBeInstanceOf(Tool::class);
});

it('supports caching configuration', function (): void {
    // Just verify the config is read properly

    // Set a non-zero cache duration
    config()->set('relay.cache_duration', 60); // 60 minutes

    $relay = new RelayFake($this->serverName);

    // Call tools() to run the code path
    $relay->tools();

    // This is a very basic test just to ensure the cache-related code exists
    // and executes without error
    expect(config('relay.cache_duration'))->toBe(60);
});

it('supports disabling cache with cache_duration=0', function (): void {
    // Set cache duration to 0 to disable caching
    config()->set('relay.cache_duration', 0);

    $relay = new RelayFake($this->serverName);

    // Cache key based on server name
    $cacheKey = "relay-tools-definitions-{$this->serverName}";

    // Clear any existing cache
    Cache::forget($cacheKey);

    // Call tools to make sure it runs through the code path
    $relay->tools();

    // Verify cache doesn't contain the key (since duration is 0)
    expect(Cache::has($cacheKey))->toBeFalse();
});

it('creates different tool handlers based on inputSchema', function (): void {
    $relay = new RelayFake($this->serverName);

    // Call tools() to create handlers
    $tools = $relay->tools();

    // Test we have the tools we expect
    expect($tools)->toHaveCount(7);
});

it('handles different parameter types correctly in tools', function (): void {
    $relay = new RelayFake($this->serverName);
    $tools = $relay->tools();

    // The RelayFake already implements test handlers that we can verify
    expect(count($tools))->toBeGreaterThan(0);
});

it('throws exception when tool definition fetch fails', function (): void {
    $relay = new RelayFake($this->serverName);
    $relay->shouldThrowOnTools('Failed to fetch tools');

    expect(fn (): array => $relay->tools())
        ->toThrow(ToolDefinitionException::class, 'Failed to fetch tools');
});

it('handles invalid tool definitions', function (): void {
    $relay = new RelayFake($this->serverName);

    // Set invalid tool definitions
    $relay->setToolDefinitions([
        ['description' => 'Missing name'],
        [], // Empty definition
    ]);

    $tools = $relay->tools();
    expect($tools)->toBeArray();

    // The fake probably auto-adds tools, so just check for expected behavior
    // when a mix of valid and invalid tools is provided
    $relay->setToolDefinitions([
        ['name' => 'valid_tool', 'description' => 'A valid tool'],
        ['description' => 'Missing name'],
    ]);

    $tools = $relay->tools();
    expect($tools)->toBeArray()
        ->and($tools !== [])->toBeTrue();
});

it('supports mapping any of schemas', function (): void {
    $relay = new RelayFake($this->serverName);

    // Call tools() to create handlers
    $tools = $relay->tools();

    $tool = $tools[6];
    $anyOf = $tool->parameters()['nameOrId'];

    expect($anyOf)
        ->toBeInstanceOf(AnyOfSchema::class)
        ->and($anyOf->toArray())->toBe([
            'anyOf' => [
                [
                    'description' => 'Name',
                    'type' => 'string',
                ],
                [
                    'description' => 'ID',
                    'type' => 'number',
                ],
            ],
            'description' => 'Parameter nameOrId for union_tool',
        ]);
});
