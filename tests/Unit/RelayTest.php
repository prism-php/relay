<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\Config;
use Mockery;
use Prism\Prism\Tool;
use Prism\Relay\Enums\Transport as EnumsTransport;
use Prism\Relay\Relay;
use Prism\Relay\RelayFactory;
use Prism\Relay\Transport\Transport;
use Tests\TestDoubles\Relay as RelayTestDouble;

beforeEach(function (): void {
    Config::set('relay.servers.github', [
        'transport' => EnumsTransport::Http,
        'url' => 'http://localhost:8000/api',
        'api_key' => null,
        'timeout' => 30,
    ]);

    Config::set('relay.cache_duration', 0);
});

test('Relay can be instantiated with server configuration', function (): void {
    $mockTransport = Mockery::mock(Transport::class);
    $relay = new RelayTestDouble('github', $mockTransport);

    expect($relay)->toBeInstanceOf(Relay::class);
    expect($relay->getServerName())->toBe('github');
});

test('Relay can be created using the factory', function (): void {
    // Mock RelayFactory to return our testable relay
    $mockTransport = Mockery::mock(Transport::class);
    $mockRelay = new RelayTestDouble('github', $mockTransport);

    $factoryMock = Mockery::mock(RelayFactory::class);
    $factoryMock->shouldReceive('make')
        ->with('github')
        ->andReturn($mockRelay);

    $relay = $factoryMock->make('github');

    expect($relay)->toBeInstanceOf(Relay::class);
    expect($relay->getServerName())->toBe('github');
});

test('Relay converts tool definitions into Prism Tool objects', function (): void {
    $toolDefinitions = [
        'tools' => [
            [
                'name' => 'search',
                'description' => 'Search for repositories',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'The search query',
                        ],
                    ],
                    'required' => ['query'],
                ],
            ],
        ],
    ];

    $mockTransport = Mockery::mock(Transport::class);
    $mockTransport->shouldReceive('start')->once();
    $mockTransport->shouldReceive('sendRequest')
        ->with('tools/list')
        ->once()
        ->andReturn($toolDefinitions);

    $relay = new RelayTestDouble('github', $mockTransport);
    $tools = $relay->tools();

    expect($tools)->toBeArray()->toHaveCount(1);
    expect($tools[0])->toBeInstanceOf(Tool::class);
    expect($tools[0]->name())->toBe('relay__github__search');
    expect($tools[0]->description())->toBe('Search for repositories');
    expect($tools[0]->hasParameters())->toBeTrue();
    expect($tools[0]->parameters())->toHaveKey('query');
});

test('Relay handles malformed tool definitions gracefully', function (): void {
    $toolDefinitions = [
        'tools' => [
            [
                'description' => 'Search for repositories',
            ],
            [
                'name' => 'incomplete_tool',
            ],
        ],
    ];

    $mockTransport = Mockery::mock(Transport::class);
    $mockTransport->shouldReceive('start')->once();
    $mockTransport->shouldReceive('sendRequest')
        ->with('tools/list')
        ->once()
        ->andReturn($toolDefinitions);

    $relay = new RelayTestDouble('github', $mockTransport);
    $tools = $relay->tools();

    expect($tools)->toBeArray()->toBeEmpty();
});

test('Relay supports different parameter types', function (): void {
    $toolDefinitions = [
        'tools' => [
            [
                'name' => 'advanced_search',
                'description' => 'Advanced search with multiple parameters',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'The search query',
                        ],
                        'limit' => [
                            'type' => 'number',
                            'description' => 'Max results to return',
                        ],
                        'include_archived' => [
                            'type' => 'boolean',
                            'description' => 'Include archived repos',
                        ],
                        'unknown_type' => [
                            'type' => 'custom_type',
                            'description' => 'Unknown parameter type',
                        ],
                    ],
                    'required' => ['query'],
                ],
            ],
        ],
    ];

    $mockTransport = Mockery::mock(Transport::class);
    $mockTransport->shouldReceive('start')->once();
    $mockTransport->shouldReceive('sendRequest')
        ->with('tools/list')
        ->once()
        ->andReturn($toolDefinitions);

    $relay = new RelayTestDouble('github', $mockTransport);
    $tools = $relay->tools();

    expect($tools)->toBeArray()->toHaveCount(1);
    expect($tools[0]->name())->toBe('relay__github__advanced_search');

    $parameters = $tools[0]->parameters();
    expect($parameters)
        ->toHaveKey('query')
        ->toHaveKey('limit')
        ->toHaveKey('include_archived')
        ->toHaveKey('unknown_type');

    expect($tools[0]->requiredParameters())
        ->toContain('query')
        ->not()->toContain('limit')
        ->not()->toContain('include_archived');
});
