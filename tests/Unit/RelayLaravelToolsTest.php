<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Prism\Relay\Enums\ToolFormat;
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

it('returns an array of Laravel AI SDK tools', function (): void {
    $relay = new RelayFake($this->serverName);
    $tools = $relay->format(ToolFormat::AI_SDK)->tools();

    expect($tools)
        ->toBeArray()
        ->not->toBeEmpty()
        ->and($tools[0])
        ->toBeInstanceOf(Tool::class);
});

it('generates namespaced tool names', function (): void {
    $relay = new RelayFake($this->serverName);
    $tools = $relay->format(ToolFormat::AI_SDK)->tools();

    expect($tools[0]->name())->toBe("relay__{$this->serverName}__test_tool");
});

it('returns correct tool description', function (): void {
    $relay = new RelayFake($this->serverName);
    $tools = $relay->format(ToolFormat::AI_SDK)->tools();

    expect($tools[0]->description())->toBe('A test tool for testing');
});

it('builds schema with correct types for primitive parameters', function (): void {
    $relay = new RelayFake($this->serverName);
    $tools = $relay->format(ToolFormat::AI_SDK)->tools();

    // test_tool has param1 (string, required), param2 (number), param3 (boolean)
    $schema = new JsonSchemaTypeFactory;
    $params = $tools[0]->schema($schema);

    expect($params)
        ->toHaveKey('param1')
        ->toHaveKey('param2')
        ->toHaveKey('param3')
        ->and($params['param1']->toArray()['type'])->toBe('string')
        ->and($params['param2']->toArray())->toHaveKey('type')
        ->and($params['param3']->toArray()['type'])->toBe('boolean');
});

it('marks required parameters via the required flag on the type', function (): void {
    $relay = new RelayFake($this->serverName);
    $tools = $relay->format(ToolFormat::AI_SDK)->tools();

    // test_tool: only param1 is required.
    // illuminate/json-schema tracks `required` as parent-object state in JSON Schema,
    // so it isn't exposed via a public method on Type — reflection is the only way to
    // verify the flag was set before the schema is serialised.
    $schema = new JsonSchemaTypeFactory;
    $params = $tools[0]->schema($schema);

    $reqProp = new \ReflectionProperty($params['param1'], 'required');
    $optProp = new \ReflectionProperty($params['param2'], 'required');

    expect($reqProp->getValue($params['param1']))->toBeTrue()
        ->and($optProp->getValue($params['param2']))->toBeNull();
});

it('falls back to string type for anyOf parameters', function (): void {
    $relay = new RelayFake($this->serverName);
    $tools = $relay->format(ToolFormat::AI_SDK)->tools();

    // union_tool (index 6) has nameOrId with anyOf
    $schema = new JsonSchemaTypeFactory;
    $params = $tools[6]->schema($schema);

    expect($params)
        ->toHaveKey('nameOrId')
        ->and($params['nameOrId']->toArray()['type'])->toBe('string');
});

it('handle() routes through to callMCPTool', function (): void {
    $relay = new RelayFake($this->serverName);
    $tools = $relay->format(ToolFormat::AI_SDK)->tools();

    $request = new Request(['param1' => 'hello']);
    $result = $tools[0]->handle($request);

    expect($result)
        ->toContain('test_tool')
        ->toContain('hello');
});

it('throws ToolDefinitionException when tool definitions fail', function (): void {
    $relay = new RelayFake($this->serverName);
    $relay->shouldThrowOnTools('Fetch failed');

    expect(fn (): array => $relay->format(ToolFormat::AI_SDK)->tools())
        ->toThrow(ToolDefinitionException::class, 'Fetch failed');
});

it('returns correct count of tools', function (): void {
    $relay = new RelayFake($this->serverName);
    $tools = $relay->format(ToolFormat::AI_SDK)->tools();

    expect($tools)->toHaveCount(7);
});

it('includes all parameters in tool schema', function (): void {
    $relay = new RelayFake($this->serverName);
    $tools = $relay->format(ToolFormat::AI_SDK)->tools();

    $schema = new JsonSchemaTypeFactory;
    $params = $tools[1]->schema($schema);

    expect($params)->toHaveKey('url')
        ->and($params['url']->toArray()['type'])->toBe('string');
});
