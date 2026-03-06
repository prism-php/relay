<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\Cache;
use Prism\Prism\Schema\AnyOfSchema;
use Prism\Prism\Tool;
use Prism\Relay\Enums\ToolFormat;
use Prism\Relay\Exceptions\ServerConfigurationException;
use Prism\Relay\Exceptions\ToolDefinitionException;
use Tests\TestDoubles\RelayFake;

beforeEach(function (): void {
    $this->serverName = 'test_server';
    config()->set('relay.servers.'.$this->serverName, [
        'url' => 'http://example.com/api',
        'timeout' => 30,
    ]);
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
    config()->set('relay.cache_duration', 60);

    $relay = new RelayFake($this->serverName);
    $relay->tools();

    expect(config('relay.cache_duration'))->toBe(60);
});

it('supports disabling cache with cache_duration=0', function (): void {
    config()->set('relay.cache_duration', 0);

    $cacheKey = "relay-tools-definitions-{$this->serverName}";
    Cache::forget($cacheKey);

    $relay = new RelayFake($this->serverName);
    $relay->tools();

    expect(Cache::has($cacheKey))->toBeFalse();
});

it('creates different tool handlers based on inputSchema', function (): void {
    $relay = new RelayFake($this->serverName);
    $tools = $relay->tools();

    expect($tools)->toHaveCount(7);
});

it('handles different parameter types correctly in tools', function (): void {
    $relay = new RelayFake($this->serverName);
    $tools = $relay->tools();

    expect($tools)->not->toBeEmpty();
});

it('throws exception when tool definition fetch fails', function (): void {
    $relay = new RelayFake($this->serverName);
    $relay->shouldThrowOnTools('Failed to fetch tools');

    expect(fn (): array => $relay->tools())
        ->toThrow(ToolDefinitionException::class, 'Failed to fetch tools');
});

it('handles invalid tool definitions', function (): void {
    $relay = new RelayFake($this->serverName);

    $relay->setToolDefinitions([
        ['description' => 'Missing name'],
        [],
    ]);

    $tools = $relay->tools();
    expect($tools)->toBeArray()->toBeEmpty();

    $relay->setToolDefinitions([
        ['name' => 'valid_tool', 'description' => 'A valid tool'],
        ['description' => 'Missing name'],
    ]);

    $tools = $relay->tools();
    expect($tools)->toBeArray()
        ->not->toBeEmpty()
        ->toHaveCount(1);
});

it('returns laravel ai tools when tool_format is aisdk', function (): void {
    config()->set('relay.tool_format', ToolFormat::AI_SDK);

    $relay = new RelayFake($this->serverName);
    $tools = $relay->tools();

    expect($tools[0])->toBeInstanceOf(\Laravel\Ai\Contracts\Tool::class);
});

it('runtime format() overrides config tool_format', function (): void {
    config()->set('relay.tool_format', ToolFormat::RELAY);

    $relay = (new RelayFake($this->serverName))->format(ToolFormat::AI_SDK);
    $tools = $relay->tools();

    expect($tools[0])->toBeInstanceOf(\Laravel\Ai\Contracts\Tool::class);
});

it('extractBaseToolName strips the relay namespace prefix', function (): void {
    $relay = new RelayFake($this->serverName);

    expect($relay->extractBaseToolNamePublic("relay__{$this->serverName}__test_tool"))
        ->toBe('test_tool');
});

it('extractBaseToolName handles tool names that contain double underscores', function (): void {
    $relay = new RelayFake($this->serverName);

    expect($relay->extractBaseToolNamePublic("relay__{$this->serverName}__my__complex__tool"))
        ->toBe('my__complex__tool');
});

it('extractBaseToolName passes through names that do not match the prefix', function (): void {
    $relay = new RelayFake($this->serverName);

    expect($relay->extractBaseToolNamePublic('plain_tool_name'))->toBe('plain_tool_name');
});

it('supports mapping any of schemas', function (): void {
    $relay = new RelayFake($this->serverName);
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
