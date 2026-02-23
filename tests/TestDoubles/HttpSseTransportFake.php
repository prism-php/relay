<?php

declare(strict_types=1);

namespace Tests\TestDoubles;

use GuzzleHttp\Psr7\Utils;
use Prism\Relay\Exceptions\TransportException;
use Prism\Relay\Transport\HttpSseTransport;
use Psr\Http\Message\StreamInterface;

class HttpSseTransportFake extends HttpSseTransport
{
    /**
     * @var array<string, mixed>
     */
    protected array $responses = [];

    protected bool $shouldFailConnect = false;

    protected bool $shouldFailPost = false;

    protected bool $shouldReturnError = false;

    protected string $errorMessage = 'Error message';

    protected int $errorCode = 400;

    protected bool $shouldTimeout = false;

    protected string $fakeSessionId = 'fake-session-123';

    /**
     * @var list<string>
     */
    protected array $sseEvents = [];

    protected int $sseEventIndex = 0;

    /**
     * @param  array<string, mixed>  $response
     */
    public function setResponse(string $method, array $response): self
    {
        $this->responses[$method] = $response;

        return $this;
    }

    public function shouldFailConnect(): self
    {
        $this->shouldFailConnect = true;

        return $this;
    }

    public function shouldFailPost(): self
    {
        $this->shouldFailPost = true;

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

    public function withFakeSessionId(string $sessionId): self
    {
        $this->fakeSessionId = $sessionId;

        return $this;
    }

    public function getSessionIdValue(): ?string
    {
        return $this->sessionId;
    }

    public function getMessageEndpointValue(): ?string
    {
        return $this->messageEndpoint;
    }

    #[\Override]
    protected function connectToSse(): void
    {
        if ($this->shouldFailConnect) {
            throw new TransportException('Failed to connect to SSE endpoint: Connection refused');
        }

        // Simulate the endpoint event
        $this->sessionId = $this->fakeSessionId;
        $this->messageEndpoint = 'http://example.com/messages/?session_id='.$this->fakeSessionId;

        // Create a fake SSE stream with queued events
        $this->sseStream = $this->createFakeSseStream();
    }

    #[\Override]
    protected function performInitialize(): void
    {
        if ($this->shouldFailPost) {
            throw new TransportException('Failed to post message to MCP server: Connection refused');
        }

        // Simulate initialize - queue an initialize response
        $initResult = $this->responses['initialize'] ?? [
            'protocolVersion' => '2024-11-05',
            'capabilities' => new \stdClass,
            'serverInfo' => [
                'name' => 'test-server',
                'version' => '1.0.0',
            ],
        ];

        $this->requestId++;
        $this->queueSseEvent('message', json_encode([
            'jsonrpc' => '2.0',
            'id' => (string) $this->requestId,
            'result' => $initResult,
        ]));

        // Read the response
        $response = $this->waitForResponse();

        if (! isset($response['protocolVersion'])) {
            throw new TransportException('Invalid initialize response: missing protocolVersion');
        }
    }

    #[\Override]
    protected function sendInitializedNotification(): void
    {
        // No-op for fake
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws TransportException
     */
    #[\Override]
    protected function postMessage(array $payload): void
    {
        if ($this->shouldFailPost) {
            throw new TransportException('Failed to post message to MCP server: Connection refused');
        }

        $method = $payload['method'] ?? '';
        $id = $payload['id'] ?? null;

        // If this is a notification (no id), just return
        if ($id === null) {
            return;
        }

        if ($this->shouldReturnError) {
            $this->queueSseEvent('message', json_encode([
                'jsonrpc' => '2.0',
                'id' => $id,
                'error' => [
                    'code' => $this->errorCode,
                    'message' => $this->errorMessage,
                    'data' => ['details' => 'Additional error information'],
                ],
            ]));

            return;
        }

        if ($this->shouldTimeout) {
            // Don't queue any event - will cause timeout
            return;
        }

        // Queue a response event
        $result = $this->responses[$method] ?? $this->getDefaultResponse($method);

        $this->queueSseEvent('message', json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    protected function getDefaultResponse(string $method): array
    {
        return match ($method) {
            'initialize' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => new \stdClass,
                'serverInfo' => [
                    'name' => 'test-server',
                    'version' => '1.0.0',
                ],
            ],
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
            default => ['status' => 'success'],
        };
    }

    protected function queueSseEvent(string $eventType, string $data): void
    {
        $this->sseEvents[] = "event: {$eventType}\ndata: {$data}\n\n";
        $this->refreshFakeSseStream();
    }

    protected function createFakeSseStream(): StreamInterface
    {
        $content = implode('', array_slice($this->sseEvents, $this->sseEventIndex));

        return Utils::streamFor($content);
    }

    protected function refreshFakeSseStream(): void
    {
        $this->sseEventIndex = 0;
        $this->sseBuffer = '';
        $this->sseStream = $this->createFakeSseStream();
    }
}
