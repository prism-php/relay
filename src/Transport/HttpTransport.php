<?php

declare(strict_types=1);

namespace Prism\Relay\Transport;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Prism\Relay\Exceptions\TransportException;

class HttpTransport implements Transport
{
    protected int $requestId = 0;

    protected bool $started = false;

    protected ?string $sessionId = null;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected array $config
    ) {}

    #[\Override]
    public function start(): void
    {
        if ($this->started) {
            return;
        }

        $this->requestId++;

        $initializePayload = [
            'jsonrpc' => '2.0',
            'id' => (string) $this->requestId,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-03-26',
                'capabilities' => new \stdClass,
                'clientInfo' => [
                    'name' => 'prism-relay',
                    'version' => '1.0.0',
                ],
            ],
        ];

        $initializeResponse = $this->sendHttpRequest($initializePayload);
        $this->validateHttpResponse($initializeResponse);
        $this->sessionId = $initializeResponse->header('Mcp-Session-Id');

        $initializeJson = $this->parseJsonRpcResponse($initializeResponse);
        $this->validateJsonRpcResponse($initializeJson);

        if (isset($initializeJson['error'])) {
            $this->handleJsonRpcError($initializeJson['error']);
        }

        $initializedNotification = [
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ];

        $notificationResponse = $this->sendHttpRequest($initializedNotification);
        $this->validateHttpResponse($notificationResponse);

        $this->started = true;
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
        $this->start();

        $this->requestId++;
        $requestPayload = $this->createRequestPayload($method, $params);

        try {
            $response = $this->sendHttpRequest($requestPayload);

            return $this->processResponse($response);
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
    public function close(): void {}

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function createRequestPayload(string $method, array $params = []): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => (string) $this->requestId,
            'method' => $method,
            // Some MCP HTTP servers require params to be an object, not an array.
            'params' => $params === [] ? new \stdClass : $params,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function sendHttpRequest(array $payload): Response
    {
        $headers = array_merge([
            // MCP Streamable HTTP requires both content types to be accepted.
            'Accept' => 'application/json, text/event-stream',
        ], $this->getHeaders());

        if ($this->sessionId) {
            $headers['Mcp-Session-Id'] = $this->sessionId;
        }

        return Http::timeout($this->getTimeout())
            ->withHeaders($headers)
            ->when(
                $this->hasApiKey(),
                fn ($http) => $http->withToken($this->getApiKey())
            )
            ->post($this->getServerUrl(), $payload);
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
            && (isset($this->config['headers']) && $this->config['headers'] !== []);
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

    protected function getServerUrl(): string
    {
        return $this->config['url'];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws TransportException
     */
    protected function processResponse(Response $response): array
    {
        $this->validateHttpResponse($response);
        $jsonResponse = $this->parseJsonRpcResponse($response);
        $this->validateJsonRpcResponse($jsonResponse);

        if (isset($jsonResponse['error'])) {
            $this->handleJsonRpcError($jsonResponse['error']);
        }

        return $jsonResponse['result'] ?? [];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws TransportException
     */
    protected function parseJsonRpcResponse(Response $response): array
    {
        $contentType = strtolower($response->header('Content-Type'));

        if (str_contains($contentType, 'text/event-stream')) {
            return $this->parseSseJsonRpcResponse($response->body());
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new TransportException('Invalid JSON response received from MCP server');
        }

        return $json;
    }

    /**
     * Parse JSON-RPC payload from an SSE response body.
     *
     * @return array<string, mixed>
     *
     * @throws TransportException
     */
    protected function parseSseJsonRpcResponse(string $body): array
    {
        $lines = preg_split("/\r\n|\n|\r/", $body) ?: [];
        $dataLines = [];
        $messages = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                if ($dataLines !== []) {
                    $decoded = json_decode(implode("\n", $dataLines), true);

                    if (is_array($decoded) && isset($decoded['jsonrpc'])) {
                        $messages[] = $decoded;
                    }

                    $dataLines = [];
                }

                continue;
            }

            if (str_starts_with($line, 'data:')) {
                $dataLines[] = ltrim(substr($line, 5));
            }
        }

        if ($dataLines !== []) {
            $decoded = json_decode(implode("\n", $dataLines), true);

            if (is_array($decoded) && isset($decoded['jsonrpc'])) {
                $messages[] = $decoded;
            }
        }

        if ($messages === []) {
            throw new TransportException('No JSON-RPC message found in SSE response');
        }

        foreach ($messages as $message) {
            if (isset($message['id']) && (string) $message['id'] === (string) $this->requestId) {
                return $message;
            }
        }

        return end($messages) ?: [];
    }

    /**
     * @throws TransportException
     */
    protected function validateHttpResponse(Response $response): void
    {
        if ($response->failed()) {
            throw new TransportException(
                "HTTP request failed with status code: {$response->status()}"
            );
        }
    }

    /**
     * @param  array<string, mixed>  $jsonResponse
     *
     * @throws TransportException
     */
    protected function validateJsonRpcResponse(array $jsonResponse): void
    {
        if (! isset($jsonResponse['jsonrpc']) ||
            $jsonResponse['jsonrpc'] !== '2.0' ||
            ! isset($jsonResponse['id']) ||
            (string) $jsonResponse['id'] !== (string) $this->requestId
        ) {
            throw new TransportException(
                'Invalid JSON-RPC 2.0 response received'
            );
        }
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
        if (! ($errorData === '' || $errorData === '0' || $errorData === false) && $errorData !== '0' && $errorData !== 'false') {
            $detailsSuffix = " Details: {$errorData}";
        }

        throw new TransportException(
            "JSON-RPC error: {$errorMessage} (code: {$errorCode}){$detailsSuffix}"
        );
    }
}
