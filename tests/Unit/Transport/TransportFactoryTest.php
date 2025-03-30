<?php

declare(strict_types=1);

namespace Tests\Unit\Transport;

use Prism\Relay\Enums\Transport as TransportEnum;
use Prism\Relay\Exceptions\ServerConfigurationException;
use Prism\Relay\Transport\HttpTransport;
use Prism\Relay\Transport\StdioTransport;
use Prism\Relay\Transport\TransportFactory;

it('creates http transport', function (): void {
    $config = [
        'url' => 'http://localhost:8000/api',
        'api_key' => 'test-api-key',
        'timeout' => 30,
    ];

    $transport = TransportFactory::create(TransportEnum::Http, $config);

    expect($transport)->toBeInstanceOf(HttpTransport::class);
});

it('creates stdio transport', function (): void {
    $config = [
        'command' => ['node', 'server.js'],
        'timeout' => 30,
    ];

    $transport = TransportFactory::create(TransportEnum::Stdio, $config);

    expect($transport)->toBeInstanceOf(StdioTransport::class);
});

it('throws exception for stdio without command', function (): void {
    $config = [
        'timeout' => 30,
    ];

    expect(fn (): \Prism\Relay\Transport\Transport => TransportFactory::create(TransportEnum::Stdio, $config))
        ->toThrow(ServerConfigurationException::class, 'The "command" configuration is required for stdio transport');
});
