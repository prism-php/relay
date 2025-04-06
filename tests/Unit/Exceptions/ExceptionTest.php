<?php

declare(strict_types=1);

namespace Tests\Unit\Exceptions;

use Prism\Relay\Exceptions\RelayException;
use Prism\Relay\Exceptions\ServerConfigurationException;
use Prism\Relay\Exceptions\ToolCallException;
use Prism\Relay\Exceptions\ToolDefinitionException;
use Prism\Relay\Exceptions\TransportException;

it('has proper exception hierarchy', function (): void {
    // All custom exceptions should extend the base RelayException
    expect(new ServerConfigurationException('test'))
        ->toBeInstanceOf(RelayException::class);

    expect(new ToolCallException('test'))
        ->toBeInstanceOf(RelayException::class);

    expect(new ToolDefinitionException('test'))
        ->toBeInstanceOf(RelayException::class);

    expect(new TransportException('test'))
        ->toBeInstanceOf(RelayException::class);
});

it('preserves previous exception', function (): void {
    $previous = new \Exception('Previous error');
    $exception = new RelayException('Test error', 0, $previous);

    expect($exception->getPrevious())->toBe($previous);
});

it('uses correct error messages', function ($exceptionClass, $message): void {
    $exception = new $exceptionClass($message);

    expect($exception->getMessage())->toBe($message);
})->with([
    [ServerConfigurationException::class, 'Configuration error'],
    [ToolCallException::class, 'Tool call failed'],
    [ToolDefinitionException::class, 'Invalid tool definition'],
    [TransportException::class, 'Transport error'],
]);

it('includes error code in TransportException', function (): void {
    $message = 'JSON-RPC error: Not found (code: 404)';
    $exception = new TransportException($message);

    expect($exception->getMessage())->toContain('404');
});
