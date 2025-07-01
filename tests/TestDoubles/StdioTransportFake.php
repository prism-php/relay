<?php

declare(strict_types=1);

namespace Tests\TestDoubles;

use Mockery;
use Prism\Relay\Exceptions\TransportException;
use Prism\Relay\Transport\StdioTransport;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

class StdioTransportFake extends StdioTransport
{
    /**
     * @var array<string, mixed>
     */
    protected array $responses = [];

    protected bool $shouldFailProcess = false;

    protected bool $shouldInvalidJsonRpc = false;

    protected bool $shouldReturnError = false;

    protected string $errorMessage = 'Error message';

    protected int $errorCode = 400;

    protected bool $processIsRunning = true;

    protected bool $shouldTimeout = false;

    protected ?string $lastMethod = null;

    /**
     * @throws \Prism\Relay\Exceptions\ServerConfigurationException
     */
    public function __construct(array $config)
    {
        parent::__construct($config);

        // Initialize mocks after parent constructor
        $this->process = Mockery::mock(Process::class);
        $this->process->shouldReceive('isRunning')->andReturn($this->processIsRunning)->byDefault();
        $this->inputStream = new InputStream;
    }

    #[\Override]
    public function start(): void
    {
        if ($this->shouldFailProcess) {
            throw new TransportException('Failed to start process');
        }
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws TransportException
     */
    #[\Override]
    public function sendRequest(string $method, array $params = []): array
    {
        $this->lastMethod = $method;

        if ($this->shouldReturnError) {
            throw new TransportException("JSON-RPC error: {$this->errorMessage} (code: {$this->errorCode})");
        }

        if ($this->shouldTimeout) {
            throw new TransportException('Timeout waiting for MCP response');
        }

        if (! $this->processIsRunning) {
            throw new TransportException('Process not running');
        }

        // Default response based on method
        return $this->responses[$method] ?? match ($method) {
            'tools/list' => [
                'tools' => [
                    [
                        'name' => 'test_tool',
                        'description' => 'A test tool',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'param1' => [
                                    'type' => 'string',
                                    'description' => 'Parameter 1',
                                ],
                            ],
                            'required' => ['param1'],
                        ],
                    ],
                ],
            ],
            'tools/call' => [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Tool execution result',
                    ],
                ],
            ],
            default => [],
        };
    }

    #[\Override]
    public function close(): void
    {
        // No-op for fake
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public function setResponse(string $method, array $response): self
    {
        $this->responses[$method] = $response;

        return $this;
    }

    public function shouldFailProcess(): self
    {
        $this->shouldFailProcess = true;
        $this->processIsRunning = false;

        return $this;
    }

    public function shouldReturnInvalidJsonRpc(): self
    {
        $this->shouldInvalidJsonRpc = true;

        return $this;
    }

    public function shouldReturnError(string $message = 'Error message', int $code = 400): self
    {
        $this->shouldReturnError = true;
        $this->errorMessage = $message;
        $this->errorCode = $code;

        return $this;
    }

    public function shouldTimeoutResponse(): self
    {
        $this->shouldTimeout = true;

        return $this;
    }

    public function shouldBeRunning(bool $running = true): self
    {
        $this->processIsRunning = $running;
        if ($this->process instanceof \Symfony\Component\Process\Process) {
            $this->process->shouldReceive('isRunning')->andReturn($running)->byDefault();
        }

        return $this;
    }

    /** @return array<string, mixed>  */
    public function getEnvironment(): array
    {
        return $this->config['env'] ?? [];
    }
}
