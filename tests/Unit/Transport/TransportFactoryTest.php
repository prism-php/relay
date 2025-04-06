<?php

declare(strict_types=1);

use Prism\Relay\Enums\Transport as TransportEnum;
use Prism\Relay\Exceptions\ServerConfigurationException;
use Prism\Relay\Transport\HttpTransport;
use Prism\Relay\Transport\StdioTransport;
use Prism\Relay\Transport\TransportFactory;

it('creates HTTP transport', function (): void {
    $config = [
        'url' => 'http://example.com/api',
        'api_key' => 'test-key',
        'timeout' => 30,
    ];

    $transport = TransportFactory::create(TransportEnum::Http, $config);

    expect($transport)
        ->toBeInstanceOf(HttpTransport::class);
});

it('creates STDIO transport', function (): void {
    $config = [
        'command' => ['test', 'command'],
        'env' => ['TEST' => 'value'],
        'timeout' => 30,
    ];

    $transport = TransportFactory::create(TransportEnum::Stdio, $config);

    expect($transport)
        ->toBeInstanceOf(StdioTransport::class);
});

it('throws exception for STDIO transport with missing command', function (): void {
    $config = [
        'env' => ['TEST' => 'value'],
        'timeout' => 30,
    ];

    expect(fn (): \Prism\Relay\Transport\Transport => TransportFactory::create(TransportEnum::Stdio, $config))
        ->toThrow(ServerConfigurationException::class, 'The "command" configuration is required for stdio transport');
});

it('throws exception for STDIO transport with missing env', function (): void {
    $config = [
        'command' => ['test', 'command'],
        'timeout' => 30,
    ];

    expect(fn (): \Prism\Relay\Transport\Transport => TransportFactory::create(TransportEnum::Stdio, $config))
        ->toThrow(ServerConfigurationException::class, 'The "env" configuration is required for stdio transport');
});
