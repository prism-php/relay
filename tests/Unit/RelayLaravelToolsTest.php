<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Prism\Relay\Enums\ToolFormat;
use Prism\Relay\Exceptions\ToolDefinitionException;
use Prism\Relay\Relay;
use Tests\TestDoubles\FakeTransport;
use Tests\TestDoubles\RelayFake;

beforeEach(function (): void {
    if (! interface_exists(\Laravel\Ai\Contracts\Tool::class)) {
        test()->skip('laravel/ai package not installed');
    }

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

it('marks required parameters correctly in the serialized schema', function (): void {
    $relay = new RelayFake($this->serverName);
    $tools = $relay->format(ToolFormat::AI_SDK)->tools();

    // test_tool: only param1 is required.
    $schema = new JsonSchemaTypeFactory;
    $params = $tools[0]->schema($schema);

    // Wrap in an object type to observe the serialized required list.
    $serialized = $schema->object($params)->toArray();

    expect($serialized)->toHaveKey('required')
        ->and($serialized['required'])->toContain('param1')
        ->and($serialized['required'])->not->toContain('param2');
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

it('builds schema with integer type', function (): void {
    $relay = new RelayFake($this->serverName);
    $relay->setToolDefinitions([[
        'name' => 'int_tool',
        'description' => 'A tool with an integer parameter',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'count' => ['type' => 'integer', 'description' => 'Count'],
            ],
            'required' => ['count'],
        ],
    ]]);

    $tools = $relay->format(ToolFormat::AI_SDK)->tools();
    $schema = new JsonSchemaTypeFactory;
    $params = $tools[0]->schema($schema);

    expect($params)->toHaveKey('count')
        ->and($params['count']->toArray()['type'])->toBe('integer');
});

it('builds schema with array type', function (): void {
    $relay = new RelayFake($this->serverName);
    $relay->setToolDefinitions([[
        'name' => 'array_tool',
        'description' => 'A tool with an array parameter',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'tags' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'List of tags',
                ],
            ],
            'required' => ['tags'],
        ],
    ]]);

    $tools = $relay->format(ToolFormat::AI_SDK)->tools();
    $schema = new JsonSchemaTypeFactory;
    $params = $tools[0]->schema($schema);

    expect($params)->toHaveKey('tags')
        ->and($params['tags']->toArray()['type'])->toBe('array');
});

it('builds schema with object type', function (): void {
    $relay = new RelayFake($this->serverName);
    $relay->setToolDefinitions([[
        'name' => 'object_tool',
        'description' => 'A tool with an object parameter',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'config' => [
                    'type' => 'object',
                    'properties' => [
                        'key' => ['type' => 'string'],
                        'value' => ['type' => 'string'],
                    ],
                    'description' => 'Config object',
                ],
            ],
            'required' => ['config'],
        ],
    ]]);

    $tools = $relay->format(ToolFormat::AI_SDK)->tools();
    $schema = new JsonSchemaTypeFactory;
    $params = $tools[0]->schema($schema);

    expect($params)->toHaveKey('config')
        ->and($params['config']->toArray()['type'])->toBe('object');
});

it('builds schema with enum constraint on a string type', function (): void {
    $relay = new RelayFake($this->serverName);
    $relay->setToolDefinitions([[
        'name' => 'enum_tool',
        'description' => 'A tool with an enum-constrained string',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'enum' => ['active', 'inactive', 'pending'],
                    'description' => 'Status',
                ],
            ],
            'required' => ['status'],
        ],
    ]]);

    $tools = $relay->format(ToolFormat::AI_SDK)->tools();
    $schema = new JsonSchemaTypeFactory;
    $params = $tools[0]->schema($schema);

    $statusArray = $params['status']->toArray();
    expect($statusArray['type'])->toBe('string')
        ->and($statusArray)->toHaveKey('enum')
        ->and($statusArray['enum'])->toBe(['active', 'inactive', 'pending']);
});

it('handle() calls the transport with the base tool name, not the namespaced name', function (): void {
    $fakeTransport = new FakeTransport;
    $fakeTransport->addResponse('tools/call', ['content' => [['type' => 'text', 'text' => 'ok']]]);

    $relay = new class($this->serverName) extends Relay
    {
        public function setTransport(\Prism\Relay\Transport\Transport $transport): void
        {
            $this->transport = $transport;
        }

        #[\Override]
        protected function fetchToolDefinitions(): array
        {
            return [[
                'name' => 'test_tool',
                'description' => 'Test tool',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['p' => ['type' => 'string', 'description' => 'P']],
                    'required' => ['p'],
                ],
            ]];
        }
    };

    $relay->setTransport($fakeTransport);
    $tools = $relay->format(ToolFormat::AI_SDK)->tools();

    $tools[0]->handle(new Request(['p' => 'val']));

    $lastRequest = $fakeTransport->lastRequest();
    expect($lastRequest['params']['name'])->toBe('test_tool');
});
