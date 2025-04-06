<?php

declare(strict_types=1);

namespace Tests\TestDoubles;

use Prism\Relay\Exceptions\TransportException;
use Prism\Relay\Transport\Transport;

class FakeTransport implements Transport
{
    /**
     * @var array<string, mixed>
     */
    protected array $responses = [];

    /**
     * @var array<array<string, mixed>>
     */
    protected array $recordedRequests = [];

    protected int $callCount = 0;

    protected bool $shouldThrowException = false;

    protected string $exceptionMessage = 'Transport error';

    /**
     * Starts the transport.
     *
     * @throws TransportException
     */
    #[\Override]
    public function start(): void
    {
        $this->throwExceptionIfEnabled('start');
        $this->recordRequest('start', []);
    }

    /**
     * Sends a request via the transport.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws TransportException
     */
    #[\Override]
    public function sendRequest(string $method, array $params = []): array
    {
        $this->throwExceptionIfEnabled('sendRequest');
        $this->recordRequest($method, $params);

        return $this->getResponseFor($method, $params);
    }

    /**
     * Closes the transport.
     *
     * @throws TransportException
     */
    #[\Override]
    public function close(): void
    {
        $this->throwExceptionIfEnabled('close');
        $this->recordRequest('close', []);
    }

    /**
     * Adds a predefined response for a method.
     *
     * @param  array<string, mixed>  $response
     */
    public function addResponse(string $method, array $response): self
    {
        $this->responses[$method] = $response;

        return $this;
    }

    /**
     * Configures the transport to throw exceptions.
     */
    public function shouldThrow(string $message = 'Transport error'): self
    {
        $this->shouldThrowException = true;
        $this->exceptionMessage = $message;

        return $this;
    }

    public function shouldNotThrow(): self
    {
        $this->shouldThrowException = false;

        return $this;
    }

    public function hasBeenCalled(): bool
    {
        return $this->callCount > 0;
    }

    public function callCount(): int
    {
        return $this->callCount;
    }

    public function lastRequest(): ?array
    {
        if ($this->recordedRequests === []) {
            return null;
        }

        return end($this->recordedRequests);
    }

    /**
     * @return array<array<string, mixed>>
     */
    public function allRequests(): array
    {
        return $this->recordedRequests;
    }

    /**
     * @param  array<string, mixed>  $params
     *
     * @throws TransportException
     */
    protected function throwExceptionIfEnabled(string $method): void
    {
        if ($this->shouldThrowException) {
            throw new TransportException("{$method}: {$this->exceptionMessage}");
        }
    }

    /**
     * Records a request to track call history.
     *
     * @param  array<string, mixed>  $params
     */
    protected function recordRequest(string $method, array $params): void
    {
        $this->callCount++;
        $this->recordedRequests[] = [
            'method' => $method,
            'params' => $params,
            'timestamp' => microtime(true),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function getResponseFor(string $method, array $params): array
    {
        if (isset($this->responses[$method])) {
            return $this->responses[$method];
        }

        if ($method === 'tools/list') {
            return [
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
            ];
        }

        if ($method === 'tools/call') {
            return [
                'result' => 'Tool executed successfully',
            ];
        }

        return [];
    }
}
