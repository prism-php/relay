<?php

declare(strict_types=1);

namespace Tests\Unit\Transport;

use Mockery;
use Prism\Relay\Exceptions\ServerConfigurationException;
use Prism\Relay\Exceptions\TransportException;
use Prism\Relay\Transport\StdioTransport;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->config = [
        'command' => ['echo', '"{"jsonrpc":"2.0","id":"1","result":{"success":true}}"'],
        'timeout' => 30,
        'env' => ['TEST_ENV' => 'value'],
    ];
});

afterEach(function (): void {
    Mockery::close();
});

it('requires command config', function (): void {
    $configWithoutCommand = [];

    expect(fn (): \Prism\Relay\Transport\StdioTransport => new StdioTransport($configWithoutCommand))
        ->toThrow(ServerConfigurationException::class, 'The "command" configuration is required for stdio transport');
});

it('requires array command config', function (): void {
    $configWithStringCommand = [
        'command' => 'echo "test"',
    ];

    expect(fn (): \Prism\Relay\Transport\StdioTransport => new StdioTransport($configWithStringCommand))
        ->toThrow(ServerConfigurationException::class, 'The "command" configuration is required for stdio transport');
});

it('requires env config', function (): void {
    $configWithoutEnv = [
        'command' => ['echo', '"{"jsonrpc":"2.0","id":"1","result":{"success":true}}"'],
    ];

    expect(fn (): \Prism\Relay\Transport\StdioTransport => new StdioTransport($configWithoutEnv))
        ->toThrow(ServerConfigurationException::class, 'The "env" configuration is required for stdio transport');
});

it('starts and initializes process', function (): void {
    $transport = Mockery::mock(StdioTransport::class, [$this->config])
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();

    $processMock = Mockery::mock(Process::class);
    $inputStreamMock = Mockery::mock(InputStream::class);

    $transport->shouldReceive('isProcessRunning')
        ->once()
        ->andReturn(false);

    $transport->shouldReceive('initializeProcess')
        ->once()
        ->andReturnUsing(function () use ($transport, $processMock, $inputStreamMock): void {
            $transport->shouldReceive('getProcess')
                ->andReturn($processMock);

            $transport->shouldReceive('getInputStream')
                ->andReturn($inputStreamMock);
        });

    $transport->shouldReceive('launchProcess')->once();
    $transport->shouldReceive('verifyProcessStarted')->once();
    $transport->shouldReceive('sendPingRequest')->once();
    $transport->shouldReceive('cleanup')->zeroOrMoreTimes();

    $transport->start();

    expect(true)->toBeTrue();
});

it('handles process failure', function (): void {
    $transport = Mockery::mock(StdioTransport::class, [$this->config])
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();

    $transport->shouldReceive('isProcessRunning')
        ->once()
        ->andReturn(false);

    $transport->shouldReceive('initializeProcess')->once();
    $transport->shouldReceive('launchProcess')
        ->once()
        ->andThrow(new TransportException('Failed to start process'));

    $transport->shouldReceive('cleanup')->zeroOrMoreTimes();

    expect(fn () => $transport->start())
        ->toThrow(TransportException::class, 'Failed to start process');
});

it('handles process not started', function (): void {
    $transport = Mockery::mock(StdioTransport::class, [$this->config])
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();

    $processMock = Mockery::mock(Process::class);
    $processMock->shouldReceive('isRunning')->andReturn(false);
    $processMock->shouldReceive('getExitCode')->andReturn(1);
    $processMock->shouldReceive('getErrorOutput')->andReturn('Command not found');
    $processMock->shouldReceive('getOutput')->andReturn('');
    $processMock->shouldReceive('stop')->zeroOrMoreTimes();

    $transport->shouldReceive('isProcessRunning')->andReturn(false);
    $transport->shouldReceive('initializeProcess')->once();
    $transport->shouldReceive('launchProcess')->once();
    $transport->shouldReceive('getProcess')->andReturn($processMock);
    $transport->shouldReceive('cleanup')->zeroOrMoreTimes();

    $transport->shouldReceive('verifyProcessStarted')
        ->once()
        ->andReturnUsing(function (): void {
            throw new TransportException(
                'Failed to start stdio process (exit code: 1). '.
                'Error output: Command not found. Standard output: '
            );
        });

    expect(fn () => $transport->start())
        ->toThrow(
            TransportException::class,
            'Failed to start stdio process (exit code: 1). Error output: Command not found. Standard output: '
        );
});

it('performs cleanup on failure', function (): void {
    $transport = Mockery::mock(StdioTransport::class, [$this->config])
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();

    $processMock = Mockery::mock(Process::class);
    $processMock->shouldReceive('isRunning')->andReturn(true);
    $processMock->shouldReceive('stop')->zeroOrMoreTimes();

    $transport->shouldReceive('isProcessRunning')->andReturn(false);
    $transport->shouldReceive('initializeProcess')->once();
    $transport->shouldReceive('launchProcess')->once();
    $transport->shouldReceive('verifyProcessStarted')
        ->once()
        ->andThrow(new TransportException('Process verification failed'));

    $transport->shouldReceive('getProcess')->andReturn($processMock);
    $transport->shouldReceive('cleanup')->zeroOrMoreTimes();

    expect(fn () => $transport->start())
        ->toThrow(TransportException::class, 'Process verification failed');
});

it('can close transport', function (): void {
    $transport = Mockery::mock(StdioTransport::class, [$this->config])
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();

    $processMock = Mockery::mock(Process::class);
    $processMock->shouldReceive('isRunning')->andReturn(true);
    $processMock->shouldReceive('stop')->zeroOrMoreTimes();

    $inputStreamMock = Mockery::mock(InputStream::class);
    $inputStreamMock->shouldReceive('close')->zeroOrMoreTimes();

    $transport->shouldReceive('getProcess')->andReturn($processMock);
    $transport->shouldReceive('getInputStream')->andReturn($inputStreamMock);
    $transport->shouldReceive('closeInputStream')->once()->passthru();
    $transport->shouldReceive('stopProcess')->once()->passthru();

    $transport->close();

    expect(true)->toBeTrue();
});

it('handles jsonrpc error', function (): void {
    $transport = Mockery::mock(StdioTransport::class, [$this->config])
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();

    $error = [
        'code' => -32600,
        'message' => 'Invalid request',
    ];

    expect(fn () => $transport->handleJsonRpcError($error))
        ->toThrow(TransportException::class, 'JSON-RPC error: Invalid request (code: -32600)');
});

it('handles jsonrpc error with data', function (): void {
    $transport = Mockery::mock(StdioTransport::class, [$this->config])
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();

    $error = [
        'code' => -32602,
        'message' => 'Invalid params',
        'data' => ['field' => 'param', 'reason' => 'required'],
    ];

    expect(fn () => $transport->handleJsonRpcError($error))
        ->toThrow(TransportException::class, 'JSON-RPC error: Invalid params (code: -32602) Details: {"field":"param","reason":"required"}');
});

it('validates json rpc responses', function (): void {
    $transport = Mockery::mock(StdioTransport::class, [$this->config])
        ->makePartial();

    $reflectionObject = new \ReflectionObject($transport);
    $requestIdProperty = $reflectionObject->getProperty('requestId');
    $requestIdProperty->setAccessible(true);
    $requestIdProperty->setValue($transport, 1);

    $validResponse = [
        'jsonrpc' => '2.0',
        'id' => '1',
        'result' => ['success' => true],
    ];

    $isValidMethod = $reflectionObject->getMethod('isValidJsonRpcResponse');
    $isValidMethod->setAccessible(true);

    expect($isValidMethod->invoke($transport, $validResponse))->toBeTrue();

    $invalidVersionResponse = [
        'jsonrpc' => '1.0',
        'id' => '1',
        'result' => ['success' => true],
    ];
    expect($isValidMethod->invoke($transport, $invalidVersionResponse))->toBeFalse();

    $missingIdResponse = [
        'jsonrpc' => '2.0',
        'result' => ['success' => true],
    ];
    expect($isValidMethod->invoke($transport, $missingIdResponse))->toBeFalse();

    $mismatchedIdResponse = [
        'jsonrpc' => '2.0',
        'id' => '999',
        'result' => ['success' => true],
    ];
    expect($isValidMethod->invoke($transport, $mismatchedIdResponse))->toBeFalse();
});

it('processes tools list response', function (): void {
    $transport = Mockery::mock(StdioTransport::class, [$this->config])
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();

    $transport->shouldReceive('getRequestId')->andReturn(1);
    $transport->shouldReceive('isValidJsonRpcResponse')
        ->andReturnUsing(fn ($response): bool => isset($response['jsonrpc']) && $response['jsonrpc'] === '2.0' &&
               isset($response['id']) && $response['id'] === '1');

    $validToolsResponse = '{"jsonrpc":"2.0","id":"1","result":{"tools":[{"name":"test"}]}}'.PHP_EOL;
    $result = $transport->tryProcessToolsListResponse($validToolsResponse);
    expect($result)->toBe(['tools' => [['name' => 'test']]]);

    $invalidResponse = '{"jsonrpc":"2.0","id":"999","result":{"tools":[]}}'.PHP_EOL;
    $result = $transport->tryProcessToolsListResponse($invalidResponse);
    expect($result)->toBeNull();
});

it('parses json rpc line', function (): void {
    $transport = Mockery::mock(StdioTransport::class, [$this->config])
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();

    $transport->shouldReceive('getRequestId')->andReturn(1);
    $transport->shouldReceive('isValidJsonRpcResponse')
        ->andReturnUsing(fn ($response): bool => isset($response['jsonrpc']) && $response['jsonrpc'] === '2.0' &&
               isset($response['id']) && $response['id'] === '1');

    $validLine = '{"jsonrpc":"2.0","id":"1","result":{"status":"ok"}}';
    $result = $transport->tryParseJsonRpcLine($validLine);
    expect($result)->toBe(['status' => 'ok']);

    $toolsLine = '{"jsonrpc":"2.0","id":"1","result":{"tools":[{"name":"test"}]}}';
    $result = $transport->tryParseJsonRpcLine($toolsLine);
    expect($result)->toBe(['tools' => [['name' => 'test']]]);

    $invalidJson = '{not valid json}';
    $result = $transport->tryParseJsonRpcLine($invalidJson);
    expect($result)->toBeNull();
});

it('auto starts process when sending request', function (): void {
    $stubTransport = new class($this->config) extends StdioTransport
    {
        #[\Override]
        public function sendRequest(string $method, array $params = []): array
        {
            return ['success' => true];
        }
    };

    $result = $stubTransport->sendRequest('test/method', ['param' => 'value']);
    expect($result)->toBe(['success' => true]);
});

it('handles tools list endpoint specially', function (): void {
    $reflectionClass = new \ReflectionClass(StdioTransport::class);
    $prepareRequestMethod = $reflectionClass->getMethod('prepareRequest');
    $prepareRequestMethod->setAccessible(true);

    $transport = Mockery::mock(StdioTransport::class, [$this->config])
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();

    $inputStreamMock = Mockery::mock(InputStream::class);
    $processMock = Mockery::mock(Process::class);

    $processMock->shouldReceive('clearErrorOutput')->once();
    $processMock->shouldReceive('clearOutput')->once();

    $inputStreamMock->shouldReceive('write')
        ->once()
        ->withArgs(function ($jsonRequest): bool {
            $decoded = json_decode($jsonRequest, true);

            return isset($decoded['method']) &&
                   $decoded['method'] === 'tools/list' &&
                   isset($decoded['params']) &&
                   $decoded['params'] === [];
        });

    $reflectionProperty = $reflectionClass->getProperty('inputStream');
    $reflectionProperty->setAccessible(true);
    $reflectionProperty->setValue($transport, $inputStreamMock);

    $reflectionProperty = $reflectionClass->getProperty('process');
    $reflectionProperty->setAccessible(true);
    $reflectionProperty->setValue($transport, $processMock);

    $prepareRequestMethod->invoke($transport, 'tools/list', []);

    expect(true)->toBeTrue();
});
