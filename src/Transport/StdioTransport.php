<?php

declare(strict_types=1);

namespace Prism\Relay\Transport;

use Prism\Relay\Exceptions\ServerConfigurationException;
use Prism\Relay\Exceptions\TransportException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

class StdioTransport implements Transport
{
    protected ?Process $process = null;

    protected ?InputStream $inputStream = null;

    protected int $requestId = 0;

    /**
     * @param  array<string, mixed>  $config
     *
     * @throws ServerConfigurationException
     */
    public function __construct(
        protected array $config
    ) {
        $this->validateConfig();
    }

    public function __destruct()
    {
        try {
            $this->close();
        } catch (\Throwable) {
            //
        }
    }

    #[\Override]
    public function start(): void
    {
        if ($this->isProcessRunning()) {
            return;
        }

        $this->initializeProcess();
        $this->launchProcess();
        $this->verifyProcessStarted();
        $this->sendPingRequest();
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
        $this->ensureProcessIsRunning();
        $this->prepareRequest($method, $params);

        return $this->waitForResponse();
    }

    /**
     * @throws TransportException
     */
    #[\Override]
    public function close(): void
    {
        try {
            $this->closeInputStream();
            $this->stopProcess();
        } catch (\Throwable $e) {
            throw new TransportException('Failed to close stdio transport: '.$e->getMessage(), previous: $e);
        }
    }

    protected function validateConfig(): void
    {
        if (! isset($this->config['command']) || ! is_array($this->config['command'])) {
            throw new ServerConfigurationException('The "command" configuration is required for stdio transport');
        }

        if (! isset($this->config['env']) || ! is_array($this->config['env'])) {
            throw new ServerConfigurationException('The "env" configuration is required for stdio transport');
        }
    }

    protected function isProcessRunning(): bool
    {
        return $this->process && $this->process->isRunning();
    }

    protected function initializeProcess(): void
    {
        $this->inputStream = new InputStream;

        $command = implode(' ', $this->config['command']);
        $timeout = $this->getTimeout();

        $this->process = Process::fromShellCommandline(
            command: $command,
            env: $this->config['env'] ?? [],
            input: $this->inputStream,
            timeout: $timeout
        );

        $this->process->setTty(false);
        $this->process->setPty(false);
    }

    protected function getTimeout(): int
    {
        return $this->config['timeout'] ?? 30;
    }

    /**
     * @throws TransportException
     */
    protected function launchProcess(): void
    {
        try {
            if (! $this->process instanceof \Symfony\Component\Process\Process) {
                throw new TransportException('Process not initialized');
            }

            $this->process->start();
            // Give the process a moment to start up
            usleep(500000);
        } catch (\Throwable $e) {
            $this->cleanup();
            throw new TransportException('Failed to start stdio process: '.$e->getMessage(), previous: $e);
        }
    }

    /**
     * @throws TransportException
     */
    protected function verifyProcessStarted(): void
    {
        if (! $this->isProcessRunning()) {
            if (! $this->process instanceof \Symfony\Component\Process\Process) {
                throw new TransportException('Process not initialized');
            }

            $exitCode = $this->process->getExitCode() ?? 'unknown';
            $errorOutput = $this->process->getErrorOutput();
            $output = $this->process->getOutput();

            $this->cleanup();

            throw new TransportException(
                "Failed to start stdio process (exit code: {$exitCode}). ".
                "Error output: {$errorOutput}. Standard output: {$output}"
            );
        }
    }

    protected function cleanup(): void
    {
        if ($this->process && $this->process->isRunning()) {
            $this->process->stop(0);
        }

        $this->process = null;
        $this->inputStream = null;
    }

    protected function sendPingRequest(): void
    {
        if (! $this->inputStream instanceof \Symfony\Component\Process\InputStream) {
            throw new TransportException('Input stream not initialized');
        }

        $this->requestId++;
        $pingRequest = json_encode([
            'jsonrpc' => '2.0',
            'id' => (string) $this->requestId,
            'method' => 'ping',
        ]).PHP_EOL;
        $this->inputStream->write($pingRequest);

        // Reset the request ID as this was just a ping
        $this->requestId--;
    }

    /**
     * @throws TransportException
     */
    protected function ensureProcessIsRunning(): void
    {
        if (! $this->isProcessRunning() || ! $this->inputStream) {
            $this->start();
        }

        if (! $this->process || ! $this->inputStream) {
            throw new TransportException('Failed to start or access the process');
        }
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function prepareRequest(string $method, array $params = []): void
    {
        if (! $this->inputStream instanceof \Symfony\Component\Process\InputStream || ! $this->process instanceof \Symfony\Component\Process\Process) {
            throw new TransportException('Transport not properly initialized');
        }

        $this->requestId++;

        $requestPayload = [
            'jsonrpc' => '2.0',
            'id' => (string) $this->requestId,
            'method' => $method,
            'params' => $params,
        ];

        // Handle special case for tools/list endpoint which requires an object
        if ($method === 'tools/list' && $params === []) {
            $requestPayload['params'] = new \stdClass;
        }

        $jsonRequest = json_encode($requestPayload, JSON_UNESCAPED_SLASHES).PHP_EOL;
        $this->inputStream->write($jsonRequest);
        $this->process->clearErrorOutput();
        $this->process->clearOutput();
    }

    /**
     * @return array<string, mixed>
     *
     * @throws TransportException
     */
    protected function waitForResponse(): array
    {
        if (! $this->process instanceof \Symfony\Component\Process\Process) {
            throw new TransportException('Process not initialized');
        }

        $startTime = microtime(true);
        $responseBuffer = '';
        $timeout = $this->getTimeout();

        while ($this->isProcessRunning() && (microtime(true) - $startTime) < $timeout) {
            $output = $this->process->getIncrementalOutput();

            if ($output === '' || $output === '0') {
                usleep(50000);

                continue;
            }

            $responseBuffer .= $output;
            $result = $this->processResponseBuffer($responseBuffer);

            if ($result !== null) {
                return $result;
            }

            usleep(50000);
        }

        return $this->handleResponseTimeout($responseBuffer, $timeout);
    }

    /**
     * @return array<string, mixed>|null
     *
     * @throws TransportException
     */
    protected function processResponseBuffer(string &$responseBuffer): ?array
    {
        // Process special case for tool listings which may come in a specific format
        $result = $this->tryProcessToolsListResponse($responseBuffer);
        if ($result !== null) {
            return $result;
        }

        // Process the buffer line by line
        $lines = explode("\n", $responseBuffer);
        $lastIdx = count($lines) - (str_ends_with($responseBuffer, "\n") ? 0 : 1);

        for ($i = 0; $i < $lastIdx; $i++) {
            $line = trim($lines[$i]);
            if ($line === '') {
                continue;
            }
            if ($line === '0') {
                continue;
            }

            $result = $this->tryParseJsonRpcLine($line);
            if ($result !== null) {
                // Update the buffer to remove processed lines
                $responseBuffer = $lastIdx < count($lines) ? $lines[$lastIdx] : '';

                return $result;
            }
        }

        // Update the buffer to remove processed lines
        $responseBuffer = $lastIdx < count($lines) ? $lines[$lastIdx] : '';

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function tryProcessToolsListResponse(string $output): ?array
    {
        if (str_contains($output, "\n")) {
            $lines = explode("\n", $output);

            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                if ($line === '0') {
                    continue;
                }

                if (str_contains($line, '"tools":[')) {
                    $parsed = json_decode($line, true);
                    if ($this->isValidJsonRpcResponse($parsed)) {
                        return $parsed['result'];
                    }
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     *
     * @throws TransportException
     */
    protected function tryParseJsonRpcLine(string $line): ?array
    {
        $parsed = json_decode($line, true);
        if (! $this->isValidJsonRpcResponse($parsed)) {
            return null;
        }

        if (isset($parsed['error'])) {
            $this->handleJsonRpcError($parsed['error']);
        }

        if (isset($parsed['result']['tools'])) {
            return $parsed['result'];
        }

        return $parsed['result'] ?? [];
    }

    protected function isValidJsonRpcResponse(mixed $parsed): bool
    {
        return json_last_error() === JSON_ERROR_NONE &&
            isset($parsed['jsonrpc']) && $parsed['jsonrpc'] === '2.0' &&
            isset($parsed['id']) && $parsed['id'] === (string) $this->requestId;
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

    /**
     * @return array<string, mixed>
     *
     * @throws TransportException
     */
    protected function handleResponseTimeout(string $responseBuffer, int $timeout): array
    {
        if (! $this->isProcessRunning()) {
            if (! $this->process instanceof \Symfony\Component\Process\Process) {
                throw new TransportException('Process terminated unexpectedly and is no longer available');
            }

            $exitCode = $this->process->getExitCode() ?? 'unknown';
            $errorOutput = $this->process->getErrorOutput();
            throw new TransportException(
                "MCP process terminated unexpectedly (exit code: {$exitCode}). ".
                "Error output: {$errorOutput}"
            );
        }

        $preview = substr($responseBuffer, 0, 100);
        $ellipsis = strlen($responseBuffer) > 100 ? '...' : '';

        throw new TransportException(
            "Timeout waiting for MCP response after {$timeout} seconds. ".
            "Last received data: {$preview}{$ellipsis}"
        );
    }

    protected function closeInputStream(): void
    {
        if ($this->inputStream instanceof \Symfony\Component\Process\InputStream) {
            $this->inputStream->close();
            $this->inputStream = null;
        }
    }

    protected function stopProcess(): void
    {
        if ($this->process && $this->process->isRunning()) {
            $this->process->stop();
            $this->process = null;
        }
    }
}
