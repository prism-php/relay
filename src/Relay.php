<?php

declare(strict_types=1);

namespace Prism\Relay;

use Illuminate\Support\Facades\Cache;
use Prism\Prism\Contracts\Schema;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Tool;
use Prism\Relay\Enums\Transport as EnumsTransport;
use Prism\Relay\Exceptions\RelayException;
use Prism\Relay\Exceptions\ServerConfigurationException;
use Prism\Relay\Exceptions\ToolCallException;
use Prism\Relay\Exceptions\ToolDefinitionException;
use Prism\Relay\Transport\Transport;
use Prism\Relay\Transport\TransportFactory;

class Relay
{
    /**
     * @var array<string, mixed>
     */
    protected array $serverConfig;

    protected Transport $transport;

    /**
     * @param  array<string, mixed>|null  $customConfig
     *
     * @throws ServerConfigurationException
     */
    public function __construct(protected string $serverName, protected ?array $customConfig = null)
    {
        $this->resolveServerConfig();
        $this->initializeTransport();
    }

    public function getServerName(): string
    {
        return $this->serverName;
    }

    /**
     * @return array<int, Tool>
     *
     * @throws ToolDefinitionException
     */
    public function tools(): array
    {
        $toolDefinitions = $this->fetchToolDefinitions();

        return $this->createToolsFromDefinitions($toolDefinitions);
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws ToolDefinitionException
     */
    protected function fetchToolDefinitions(): array
    {
        $cacheKey = "relay-tools-definitions-{$this->serverName}";
        $cacheDuration = config('relay.cache_duration', 60);

        if ($cacheDuration > 0 && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $this->transport->start();

        try {
            $toolsResult = $this->transport->sendRequest('tools/list');
            $toolDefinitions = $this->parseToolsResult($toolsResult);

            if ($cacheDuration > 0) {
                Cache::put($cacheKey, $toolDefinitions, $cacheDuration * 60);
            }

            return $toolDefinitions;
        } catch (\Throwable $e) {
            throw new ToolDefinitionException(
                "Failed to fetch tools from MCP server '{$this->serverName}': {$e->getMessage()}",
                previous: $e
            );
        }
    }

    /**
     * @param  array<string, mixed>  $toolsResult
     * @return array<int, array<string, mixed>>
     */
    protected function parseToolsResult(array $toolsResult): array
    {
        $tools = data_get($toolsResult, 'tools');

        if (is_array($tools)) {
            return $tools;
        }

        if (! isset($toolsResult['tools'])) {
            return array_values($toolsResult);
        }

        return [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $toolDefinitions
     * @return array<int, Tool>
     */
    protected function createToolsFromDefinitions(array $toolDefinitions): array
    {
        $tools = [];

        foreach ($toolDefinitions as $definition) {
            if (! $this->isValidToolDefinition($definition)) {
                continue;
            }

            $tool = $this->createBaseTool($definition);
            $this->addParametersToTool($tool, $definition);

            $tools[] = $tool;
        }

        return $tools;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    protected function isValidToolDefinition(array $definition): bool
    {
        return isset($definition['name']) && isset($definition['description']);
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    protected function createBaseTool(array $definition): Tool
    {
        $toolName = "relay__{$this->serverName}__{$definition['name']}";

        $tool = new Tool;
        $tool->as($toolName);
        $tool->for($definition['description']);

        $handlerFunction = $this->createHandlerFunction($toolName, $definition);
        $tool->using($handlerFunction);

        return $tool;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    protected function createHandlerFunction(string $toolName, array $definition): callable
    {
        $this->getRequiredParameters($definition);

        // Check for specific tool types based on required parameters and create specialized handlers
        if ($this->isUrlBasedTool($definition)) {
            return fn ($url = null): string => $this->callMCPTool($toolName, ['url' => $url]);
        }

        if ($this->isSelectorBasedTool($definition)) {
            return fn ($selector = null): string => $this->callMCPTool($toolName, ['selector' => $selector]);
        }

        if ($this->isSelectorValueBasedTool($definition)) {
            return fn ($selector = null, $value = null): string => $this->callMCPTool($toolName, [
                'selector' => $selector,
                'value' => $value,
            ]);
        }

        if ($this->hasNameParameter($definition)) {
            return $this->createNameBasedHandler($toolName);
        }

        if ($this->hasScriptParameter($definition)) {
            return fn ($script = null): string => $this->callMCPTool($toolName, ['script' => $script]);
        }

        // Default generic handler for any other tool - handle both individual params and array
        return function (...$args) use ($toolName, $definition): string {
            // Check if we have named parameters (associative array)
            if ($args !== [] && array_keys($args) !== range(0, count($args) - 1)) {
                // We have named parameters, use them directly
                return $this->callMCPTool($toolName, $args);
            }
            // If first argument is an array, use it as parameters
            if (count($args) === 1 && isset($args[0]) && is_array($args[0])) {
                return $this->callMCPTool($toolName, $args[0]);
            }

            // Otherwise, map positional arguments to parameter names
            $requiredParams = $this->getRequiredParameters($definition);
            $parameters = [];

            foreach ($requiredParams as $index => $paramName) {
                if (isset($args[$index])) {
                    $parameters[$paramName] = $args[$index];
                }
            }

            return $this->callMCPTool($toolName, $parameters);
        };
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<int, string>
     */
    protected function getRequiredParameters(array $definition): array
    {
        $required = data_get($definition, 'inputSchema.required', []);

        return is_array($required) ? $required : [];
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    protected function isUrlBasedTool(array $definition): bool
    {
        $properties = data_get($definition, 'inputSchema.properties', []);
        $required = data_get($definition, 'inputSchema.required', []);

        return isset($properties['url']) && count($required) === 1 && in_array('url', $required);
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    protected function isSelectorBasedTool(array $definition): bool
    {
        $properties = data_get($definition, 'inputSchema.properties', []);
        $required = data_get($definition, 'inputSchema.required', []);

        return isset($properties['selector']) && count($required) === 1 && in_array('selector', $required);
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    protected function isSelectorValueBasedTool(array $definition): bool
    {
        $properties = data_get($definition, 'inputSchema.properties', []);
        $required = data_get($definition, 'inputSchema.required', []);

        return isset($properties['selector']) && isset($properties['value']) &&
            count($required) === 2 && in_array('selector', $required) && in_array('value', $required);
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    protected function hasNameParameter(array $definition): bool
    {
        return data_get($definition, 'inputSchema.properties.name') !== null;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    protected function hasScriptParameter(array $definition): bool
    {
        return data_get($definition, 'inputSchema.properties.script') !== null;
    }

    protected function createNameBasedHandler(string $toolName): callable
    {
        return function ($name = null, $selector = null, $width = null, $height = null) use ($toolName): string {
            $params = ['name' => $name];
            if ($selector !== null) {
                $params['selector'] = $selector;
            }
            if ($width !== null) {
                $params['width'] = $width;
            }
            if ($height !== null) {
                $params['height'] = $height;
            }

            return $this->callMCPTool($toolName, $params);
        };
    }

    /**
     * @param  array<string, mixed>  $definition
     *
     * @throws RelayException
     */
    protected function addParametersToTool(Tool $tool, array $definition): void
    {
        $properties = data_get($definition, 'inputSchema.properties', []);

        if (empty($properties)) {
            return;
        }

        $definitionName = data_get($definition, 'name', 'unknown');
        $requiredParams = data_get($definition, 'inputSchema.required', []);

        foreach ($this->getSchemeParameters($properties, $definitionName) as $parameter) {
            $required = in_array($parameter->name(), $requiredParams);
            $tool->withParameter($parameter, $required);
        }
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<int, Schema>
     *
     * @throws RelayException
     */
    protected function getSchemeParameters(array $properties, string $definitionName): array
    {
        $parameters = [];
        foreach ($properties as $name => $property) {
            $parameters[] = $this->getSchemeParameter($name, $property, $definitionName);
        }

        return $parameters;
    }

    /**
     * @param  array<string, mixed>  $property
     *
     * @throws RelayException
     */
    protected function getSchemeParameter(string $name, array $property, string $definitionName): Schema
    {
        $type = data_get($property, 'type');
        $description = $this->getParameterDescription($name, $property, $definitionName);

        return match ($type) {
            'string' => new StringSchema($name, $description),
            'number', 'integer' => new NumberSchema($name, $description),
            'boolean' => new BooleanSchema($name, $description),
            'enum' => new EnumSchema($name, $description, data_get($property, 'options', [])),
            'object' => new ObjectSchema($name, $description, $this->getSchemeParameters(data_get($property, 'properties', []), $definitionName), data_get($property, 'required', []), data_get($property, 'allowAdditionalProperties', false)),
            'array' => new ArraySchema($name, $description, $this->getSchemeParameter('', data_get($property, 'items', []), $definitionName)),
            default => throw new RelayException("Unknown type {$type} for {$name} in {$definitionName}"),
        };
    }

    /**
     * @param  array<string, mixed>  $property
     */
    protected function getParameterDescription(string $name, array $property, string $definitionName): string
    {
        $description = data_get($property, 'description');

        if ($description) {
            return $description;
        }

        if ($name === 'url') {
            return 'URL to navigate to';
        }

        return "Parameter {$name} for {$definitionName}";
    }

    /**
     * @param  array<string|int, mixed>|object|string  $parameters
     *
     * @throws ToolCallException
     */
    protected function callMCPTool(string $toolName, $parameters): string
    {
        try {
            $baseToolName = $this->extractBaseToolName($toolName);
            $normalizedParams = $this->normalizeParameters($baseToolName, $parameters);

            $result = $this->executeMCPToolCall($baseToolName, $normalizedParams);

            return $this->formatToolResponse($result);
        } catch (\Throwable $e) {
            throw new ToolCallException($e->getMessage(), previous: $e);
        }
    }

    protected function extractBaseToolName(string $toolName): string
    {
        if (str_starts_with($toolName, 'relay__')) {
            $parts = explode('__', $toolName);
            if (count($parts) >= 3) {
                return end($parts);
            }
        }

        return $toolName;
    }

    /**
     * @param  array<string|int, mixed>|object|string  $parameters
     * @return array<string, mixed>
     */
    protected function normalizeParameters(string $baseToolName, $parameters): array
    {
        // If the parameter is a string, convert it to a default parameter format
        if (is_string($parameters)) {
            // If the tool requires a URL, use that as the key
            if ($this->isUrlParameter($baseToolName, $parameters)) {
                return ['url' => $parameters];
            }

            // Default to using 'text' as the key
            return ['text' => $parameters];
        }

        // If the parameter is an object, convert it to an array
        if (is_object($parameters)) {
            return (array) $parameters;
        }

        // If it's already an array, return it
        if (is_array($parameters)) {
            return $parameters;
        }

        // Default to empty parameters
        return [];
    }

    protected function isUrlParameter(string $toolName, string $parameter): bool
    {
        return (filter_var($parameter, FILTER_VALIDATE_URL) !== false) &&
            (str_starts_with($parameter, 'http://') || str_starts_with($parameter, 'https://'));
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    protected function executeMCPToolCall(string $toolName, array $parameters): array
    {
        // MCP requires arguments to be an object, not an array
        $normalizedParamsObject = (object) $parameters;

        $requestParams = [
            'name' => $toolName,
            'arguments' => $normalizedParamsObject,
        ];

        // Call the tool using JSON-RPC format with correct MCP endpoint: tools/call
        return $this->transport->sendRequest('tools/call', $requestParams);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function formatToolResponse(array $response): string
    {
        // MCP responses may have a content array with text and image items
        if (isset($response['content']) && is_array($response['content'])) {
            return $this->formatContentResponse($response);
        }

        // For other response formats, convert to a string
        return $this->convertResponseToString($response);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function formatContentResponse(array $response): string
    {
        $texts = [];
        $images = [];
        $isError = data_get($response, 'isError', false);
        $content = data_get($response, 'content', []);

        foreach ($content as $item) {
            $type = data_get($item, 'type');

            if ($type === 'text' && data_get($item, 'text')) {
                $texts[] = data_get($item, 'text');
            } elseif ($type === 'image' && data_get($item, 'data')) {
                $images[] = '[Image data available]';
            }
        }

        $prefix = $isError ? 'ERROR: ' : '';

        // Return text content if available
        if ($texts !== []) {
            return $prefix.implode("\n", $texts);
        }

        // Return image references if available and no text
        if ($images !== []) {
            return $prefix.implode("\n", $images);
        }

        // Default message for empty content
        return $prefix.'Tool executed'.($prefix === '' ? ' successfully' : '');
    }

    protected function convertResponseToString(mixed $response): string
    {
        // Default success message
        $defaultMessage = 'Tool executed successfully';

        if (is_string($response)) {
            return $response;
        }

        if (is_array($response)) {
            $json = json_encode($response, JSON_PRETTY_PRINT);

            return $json !== false ? $json : $defaultMessage;
        }

        if (is_scalar($response)) {
            return (string) $response;
        }

        if (is_object($response)) {
            if (method_exists($response, '__toString')) {
                return (string) $response;
            }

            $json = json_encode($response, JSON_PRETTY_PRINT);

            return $json !== false ? $json : 'Tool returned an object result';
        }

        return $defaultMessage;
    }

    /**
     * @throws ServerConfigurationException
     */
    protected function resolveServerConfig(): void
    {
        if ($this->customConfig !== null) {
            $this->serverConfig = $this->customConfig;

            return;
        }

        if (function_exists('app') && app()->bound('config')) {
            $servers = config('relay.servers', []);

            if (! isset($servers[$this->serverName])) {
                throw new ServerConfigurationException("MCP server '{$this->serverName}' is not configured.");
            }

            $this->serverConfig = $servers[$this->serverName];
        } else {
            // Fallback for testing
            $this->serverConfig = [
                'url' => 'http://localhost:8000/api',
                'api_key' => null,
                'timeout' => 30,
            ];
        }
    }

    /**
     * @throws ServerConfigurationException
     */
    protected function initializeTransport(): void
    {
        $transportType = $this->serverConfig['transport'] ?? EnumsTransport::Http;

        $this->transport = TransportFactory::create($transportType, $this->serverConfig);
    }
}
