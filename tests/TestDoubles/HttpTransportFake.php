<?php

declare(strict_types=1);

namespace Tests\TestDoubles;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Mockery;
use Prism\Relay\Transport\HttpTransport;

class HttpTransportFake extends HttpTransport
{
    /**
     * @var array<string, mixed>
     */
    protected array $responses = [];

    protected bool $failHttp = false;

    protected int $failStatus = 500;

    protected bool $invalidJsonRpc = false;

    protected bool $returnError = false;

    protected string $errorMessage = 'Error message';

    protected int $errorCode = 400;

    /**
     * @param  array<string, mixed>  $response
     */
    public function setResponse(string $method, array $response): self
    {
        $this->responses[$method] = $response;

        return $this;
    }

    public function failHttpRequest(int $status = 500): self
    {
        $this->failHttp = true;
        $this->failStatus = $status;

        return $this;
    }

    public function returnInvalidJsonRpc(): self
    {
        $this->invalidJsonRpc = true;

        return $this;
    }

    public function returnJsonRpcError(string $message = 'Error message', int $code = 400): self
    {
        $this->returnError = true;
        $this->errorMessage = $message;
        $this->errorCode = $code;

        return $this;
    }
    /**
     * @param  array<string, mixed>  $params
     */
    #[\Override]
    protected function sendHttpRequest(array $payload): Response
    {
        if ($this->failHttp) {
            return $this->mockFailedResponse();
        }

        $responseData = $this->buildResponseData($payload);

        // Create a mock response instead of using Http::response()
        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->andReturn($responseData);
        $response->shouldReceive('failed')->andReturn(false);
        $response->shouldReceive('status')->andReturn(200);

        return $response;
    }

    protected function mockFailedResponse(): Response
    {
        $response = Mockery::mock(Response::class);
        $response->shouldReceive('failed')->andReturn(true);
        $response->shouldReceive('status')->andReturn($this->failStatus);

        return $response;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function buildResponseData(array $payload): array
    {
        $method = $payload['method'] ?? '';
        $id = $payload['id'] ?? '';

        if ($this->invalidJsonRpc) {
            return [
                'jsonrpc' => '1.0', // Invalid version
                'id' => 'wrong-id',
                'result' => [],
            ];
        }

        if ($this->returnError) {
            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'error' => [
                    'code' => $this->errorCode,
                    'message' => $this->errorMessage,
                    'data' => ['details' => 'Additional error information'],
                ],
            ];
        }

        if (isset($this->responses[$method])) {
            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => $this->responses[$method],
            ];
        }

        // Default response based on method
        $result = match ($method) {
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

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];
    }
}
