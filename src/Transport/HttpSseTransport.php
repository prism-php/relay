<?php

declare(strict_types=1);

namespace Prism\Relay\Transport;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Prism\Relay\Exceptions\TransportException;
use Psr\Http\Message\StreamInterface;

class HttpSseTransport implements Transport
{
    protected int $requestId = 0;

    protected ?string $sessionId = null;

    protected ?string $messageEndpoint = null;

    protected ?StreamInterface $sseStream = null;

    protected bool $initialized = false;

    protected string $sseBuffer = '';

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected array $config
    ) {}

    public function __destruct()
    {
        try {
            $this->close();
        } catch (\Throwable) {
            //
        }
    }

    /**
     * @throws TransportException
     */
    #[\Override]
    public function start(): void
    {
        if ($this->initialized) {
            return;
        }

        try {
            $this->connectToSse();
            $this->performInitialize();
            $this->sendInitializedNotification();
            $this->initialized = true;
        } catch (\Throwable $e) {
            $this->close();

            throw $e;
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
        $this->ensureConnected();

        $this->requestId++;
        $requestPayload = $this->createRequestPayload($method, $params);

        try {
            $this->postMessage($requestPayload);

            return $this->waitForResponse();
        } catch (\Throwable $e) {
            if ($e instanceof TransportException) {
                throw $e;
            }

            throw new TransportException(
                "Failed to send request to MCP server: {$e->getMessage()}",
                previous: $e
            );
        }
    }

    #[\Override]
    public function close(): void
    {
        $this->closeSseStream();
        $this->sessionId = null;
        $this->messageEndpoint = null;
        $this->initialized = false;
        $this->sseBuffer = '';
    }

    /**
     * @throws TransportException
     */
    protected function connectToSse(): void
    {
        try {
            $response = $this->buildSseRequest()
                ->withOptions(['stream' => true])
                ->withHeaders([
                    'Accept' => 'text/event-stream',
                    'Cache-Control' => 'no-cache',
                ])
                ->get($this->getSseUrl());

            if ($response->failed()) {
                throw new TransportException(
                    "SSE connection failed with status code: {$response->status()}"
                );
            }

            $this->sseStream = $response->toPsrResponse()->getBody();

            // Read the initial endpoint event
            $this->readEndpointEvent();
        } catch (\Throwable $e) {
            if ($e instanceof TransportException) {
                throw $e;
            }

            throw new TransportException(
                "Failed to connect to SSE endpoint: {$e->getMessage()}",
                previous: $e
            );
        }
    }

    /**
     * @throws TransportException
     */
    protected function readEndpointEvent(): void
    {
        $event = $this->readNextEvent();

        if ($event === null || $event['type'] !== 'endpoint' || $event['data'] === '') {
            throw new TransportException(
                'Timeout waiting for endpoint event from SSE stream after '.$this->getTimeout().' seconds'
            );
        }

        $this->parseEndpointData($event['data']);
    }

    /**
     * @throws TransportException
     */
    protected function parseEndpointData(string $data): void
    {
        // Data is like: /messages/?session_id=abc123
        $data = trim($data);

        if (! str_contains($data, 'session_id=')) {
            throw new TransportException(
                "Invalid endpoint data received: {$data}"
            );
        }

        // Extract session ID using regex to avoid parse_str (security preset)
        if (! preg_match('/session_id=([^&\s]+)/', $data, $matches) || $matches[1] === '') {
            throw new TransportException(
                "No session_id found in endpoint data: {$data}"
            );
        }

        $this->sessionId = $matches[1];

        // Build the full message endpoint URL
        $baseUrl = $this->getBaseUrl();
        $path = parse_url($data, PHP_URL_PATH);
        $this->messageEndpoint = rtrim($baseUrl, '/').$path.'?session_id='.$this->sessionId;
    }

    /**
     * @throws TransportException
     */
    protected function performInitialize(): void
    {
        $this->requestId++;
        $initializePayload = [
            'jsonrpc' => '2.0',
            'id' => (string) $this->requestId,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => new \stdClass,
                'clientInfo' => [
                    'name' => 'prism-relay',
                    'version' => '1.0.0',
                ],
            ],
        ];

        try {
            $this->postMessage($initializePayload);

            // Read the initialize response from SSE stream
            $response = $this->waitForResponse();

            // Verify we got a valid initialize response
            if (! isset($response['protocolVersion'])) {
                throw new TransportException('Invalid initialize response: missing protocolVersion');
            }
        } catch (\Throwable $e) {
            if ($e instanceof TransportException) {
                throw $e;
            }

            throw new TransportException(
                "Failed to initialize MCP session: {$e->getMessage()}",
                previous: $e
            );
        }
    }

    protected function sendInitializedNotification(): void
    {
        $notification = [
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ];

        try {
            $this->postMessage($notification);
        } catch (\Throwable) {
            // Notifications don't require a response, so we can silently ignore errors
        }
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function createRequestPayload(string $method, array $params = []): array
    {
        $payload = [
            'jsonrpc' => '2.0',
            'id' => (string) $this->requestId,
            'method' => $method,
            'params' => $params,
        ];

        // Handle special case for tools/list endpoint which requires an object
        if ($method === 'tools/list' && $params === []) {
            $payload['params'] = new \stdClass;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws TransportException
     */
    protected function postMessage(array $payload): void
    {
        if ($this->messageEndpoint === null) {
            throw new TransportException('Message endpoint not established. Call start() first.');
        }

        try {
            $response = $this->buildPostRequest()
                ->post($this->messageEndpoint, $payload);

            if ($response->failed()) {
                throw new TransportException(
                    "HTTP request failed with status code: {$response->status()}"
                );
            }
        } catch (\Throwable $e) {
            if ($e instanceof TransportException) {
                throw $e;
            }

            throw new TransportException(
                "Failed to post message to MCP server: {$e->getMessage()}",
                previous: $e
            );
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws TransportException
     */
    protected function waitForResponse(): array
    {
        $timeout = $this->getTimeout();
        $startTime = microtime(true);

        while ((microtime(true) - $startTime) < $timeout) {
            $event = $this->readNextEvent();

            if ($event === null) {
                break;
            }

            $result = $this->processSSEEvent($event['type'], $event['data']);

            if ($result !== null) {
                return $result;
            }
        }

        throw new TransportException(
            "Timeout waiting for MCP response after {$timeout} seconds"
        );
    }

    /**
     * @return array<string, mixed>|null
     *
     * @throws TransportException
     */
    protected function processSSEEvent(?string $eventType, string $data): ?array
    {
        // Only process "message" events (or events with no type, which default to "message")
        if ($eventType !== null && $eventType !== 'message') {
            return null;
        }

        $parsed = json_decode($data, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        // Check if this is a valid JSON-RPC response matching our request
        if (! $this->isValidJsonRpcResponse($parsed)) {
            return null;
        }

        if (isset($parsed['error'])) {
            $this->handleJsonRpcError($parsed['error']);
        }

        return $parsed['result'] ?? [];
    }

    /**
     * Read the next complete SSE event from the stream.
     *
     * @return array{type: ?string, data: string}|null
     */
    protected function readNextEvent(): ?array
    {
        $startTime = microtime(true);
        $timeout = $this->getTimeout();

        $eventType = null;
        $dataBuffer = '';

        while ((microtime(true) - $startTime) < $timeout) {
            $line = $this->readSseLine();

            if ($line === null) {
                usleep(50000);

                continue;
            }

            $line = rtrim($line, "\r");

            // Blank line = end of event
            if ($line === '') {
                if ($dataBuffer !== '') {
                    return ['type' => $eventType, 'data' => $dataBuffer];
                }

                $eventType = null;

                continue;
            }

            if (str_starts_with($line, 'event:')) {
                $eventType = trim(substr($line, 6));
            } elseif (str_starts_with($line, 'data:')) {
                $data = substr($line, 5);
                if (str_starts_with($data, ' ')) {
                    $data = substr($data, 1);
                }
                $dataBuffer .= ($dataBuffer !== '' ? "\n" : '').$data;
            }
            // Ignore id:, retry:, and comment lines (starting with :)
        }

        // Handle trailing event without final blank line
        if ($dataBuffer !== '') {
            return ['type' => $eventType, 'data' => $dataBuffer];
        }

        return null;
    }

    protected function readSseLine(): ?string
    {
        if (! $this->sseStream instanceof StreamInterface || $this->sseStream->eof()) {
            return null;
        }

        // Try to read from the stream character by character until we find a newline
        while (! $this->sseStream->eof()) {
            try {
                $char = $this->sseStream->read(1);
            } catch (\Throwable) {
                return null;
            }

            if ($char === '') {
                // No data available yet
                return null;
            }

            if ($char === "\n") {
                $line = $this->sseBuffer;
                $this->sseBuffer = '';

                return $line;
            }

            $this->sseBuffer .= $char;
        }

        return null;
    }

    /**
     * @throws TransportException
     */
    protected function ensureConnected(): void
    {
        if (! $this->initialized || ! $this->sseStream instanceof StreamInterface || $this->messageEndpoint === null) {
            $this->start();
        }
    }

    protected function closeSseStream(): void
    {
        if ($this->sseStream instanceof StreamInterface) {
            try {
                $this->sseStream->close();
            } catch (\Throwable) {
                //
            }
            $this->sseStream = null;
        }
    }

    protected function isValidJsonRpcResponse(mixed $parsed): bool
    {
        return is_array($parsed) &&
            isset($parsed['jsonrpc']) && $parsed['jsonrpc'] === '2.0' &&
            isset($parsed['id']) && (string) $parsed['id'] === (string) $this->requestId;
    }

    /**
     * @param  array<string, mixed>  $error
     *
     * @throws TransportException
     */
    protected function handleJsonRpcError(array $error): void
    {
        $errorMessage = $error['message'] ?? 'Unknown error';
        $errorCode = $error['code'] ?? -1;
        $errorData = isset($error['data']) ? json_encode($error['data']) : '';

        $detailsSuffix = '';
        if ($errorData !== '' && $errorData !== false) {
            $detailsSuffix = " Details: {$errorData}";
        }

        throw new TransportException(
            "JSON-RPC error: {$errorMessage} (code: {$errorCode}){$detailsSuffix}"
        );
    }

    protected function buildSseRequest(): PendingRequest
    {
        return Http::timeout(0)
            ->connectTimeout($this->getTimeout())
            ->when(
                $this->hasApiKey(),
                fn (PendingRequest $http) => $http->withToken($this->getApiKey())
            )
            ->when(
                $this->hasHeaders(),
                fn (PendingRequest $http) => $http->withHeaders($this->getHeaders())
            );
    }

    protected function buildPostRequest(): PendingRequest
    {
        return Http::timeout($this->getTimeout())
            ->acceptJson()
            ->contentType('application/json')
            ->when(
                $this->hasApiKey(),
                fn (PendingRequest $http) => $http->withToken($this->getApiKey())
            )
            ->when(
                $this->hasHeaders(),
                fn (PendingRequest $http) => $http->withHeaders($this->getHeaders())
            );
    }

    protected function getSseUrl(): string
    {
        return $this->config['url'];
    }

    protected function getBaseUrl(): string
    {
        $url = $this->config['url'];
        $parsed = parse_url((string) $url);

        return ($parsed['scheme'] ?? 'http').'://'.($parsed['host'] ?? 'localhost').
            (isset($parsed['port']) ? ':'.$parsed['port'] : '');
    }

    protected function getTimeout(): int
    {
        return $this->config['timeout'] ?? 30;
    }

    protected function hasApiKey(): bool
    {
        return isset($this->config['api_key']) && $this->config['api_key'] !== null;
    }

    protected function hasHeaders(): bool
    {
        return isset($this->config['headers'])
            && is_array($this->config['headers'])
            && $this->config['headers'] !== [];
    }

    protected function getApiKey(): string
    {
        return (string) ($this->config['api_key'] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getHeaders(): array
    {
        return $this->config['headers'] ?? [];
    }
}
