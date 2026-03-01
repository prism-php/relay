<?php

declare(strict_types=1);

namespace Prism\Relay\Transport;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Prism\Relay\Exceptions\AuthorizationException;
use Prism\Relay\Exceptions\RelayException;
use Prism\Relay\Exceptions\TransportException;

class HttpTransport implements Transport
{
    protected int $requestId = 0;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected array $config
    ) {}

    #[\Override]
    public function start(): void {}

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws TransportException
     */
    #[\Override]
    public function sendRequest(string $method, array $params = []): array
    {
        $this->requestId++;
        $requestPayload = $this->createRequestPayload($method, $params);

        try {
            $response = $this->sendHttpRequest($requestPayload);

            return $this->processResponse($response);
        } catch (\Throwable $e) {
            if ($e instanceof RelayException) {
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
            'params' => $params,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function sendHttpRequest(array $payload): Response
    {
        $token = $this->resolveAuthToken();

        return Http::timeout($this->getTimeout())
            ->acceptJson()
            ->when(
                $token !== null,
                fn ($http) => $http->withToken((string) $token)
            )
            ->when(
                $this->hasHeaders(),
                fn ($http) => $http->withHeaders($this->getHeaders())
            )
            ->post($this->getServerUrl(), $payload);
    }

    /**
     * Resolve the authentication token, preferring access_token over api_key.
     */
    protected function resolveAuthToken(): ?string
    {
        if ($this->hasAccessToken()) {
            return $this->getAccessToken();
        }

        if ($this->hasApiKey()) {
            return $this->getApiKey();
        }

        return null;
    }

    protected function getTimeout(): int
    {
        return $this->config['timeout'] ?? 30;
    }

    protected function hasAccessToken(): bool
    {
        return isset($this->config['access_token']);
    }

    protected function getAccessToken(): string
    {
        return (string) ($this->config['access_token'] ?? '');
    }

    protected function hasApiKey(): bool
    {
        return isset($this->config['api_key']);
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
        $jsonResponse = $response->json();
        $this->validateJsonRpcResponse($jsonResponse);

        if (isset($jsonResponse['error'])) {
            $this->handleJsonRpcError($jsonResponse['error']);
        }

        return $jsonResponse['result'] ?? [];
    }

    /**
     * @throws AuthorizationException
     * @throws TransportException
     */
    protected function validateHttpResponse(Response $response): void
    {
        if ($response->status() === 401) {
            throw new AuthorizationException(
                'MCP server returned 401 Unauthorized. The access token may be missing, expired, or invalid.'
            );
        }

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
        $errorData = isset($error['data']) ? json_encode($error['data']) : null;

        $detailsSuffix = $errorData !== null && $errorData !== false
            ? " Details: {$errorData}"
            : '';

        throw new TransportException(
            "JSON-RPC error: {$errorMessage} (code: {$errorCode}){$detailsSuffix}"
        );
    }
}
