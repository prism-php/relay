<?php

declare(strict_types=1);

namespace Prism\Relay;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Contracts\Schema;
use Prism\Prism\Schema\AnyOfSchema;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Tool;
use Prism\Relay\Enums\ToolFormat;
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

    protected ?ToolFormat $runtimeFormat = null;

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

    public function format(ToolFormat $format): static
    {
        $this->runtimeFormat = $format;

        return $this;
    }

    /**
     * @return array<int, Tool|\Laravel\Ai\Contracts\Tool>
     *
     * @throws ToolDefinitionException
     */
    public function tools(): array
    {
        $format = $this->runtimeFormat ?? config('relay.tool_format', ToolFormat::RELAY);

        if ($format === ToolFormat::AI_SDK) {
            if (! interface_exists(\Laravel\Ai\Contracts\Tool::class) || ! interface_exists(\Illuminate\Contracts\JsonSchema\JsonSchema::class)) {
                throw new ToolDefinitionException(
                    'ToolFormat::AI_SDK requires the laravel/ai and illuminate/json-schema packages. Install them with: composer require laravel/ai illuminate/json-schema'
                );
            }

            return $this->createLaravelToolsFromDefinitions($this->fetchToolDefinitions());
        }

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
        if (! isset($toolsResult['tools'])) {
            return array_values($toolsResult);
        }

        return is_array($toolsResult['tools']) ? $toolsResult['tools'] : [];
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
     * @param  array<int, array<string, mixed>>  $toolDefinitions
     * @return array<int, \Laravel\Ai\Contracts\Tool>
     */
    protected function createLaravelToolsFromDefinitions(array $toolDefinitions): array
    {
        $tools = [];

        foreach ($toolDefinitions as $definition) {
            if (! $this->isValidToolDefinition($definition)) {
                continue;
            }

            $toolName = "relay__{$this->serverName}__{$definition['name']}";
            $toolDescription = $definition['description'];

            $handler = fn (string $name, array $params): string => $this->callMCPTool($name, $params);
            $schemaFn = fn (\Illuminate\Contracts\JsonSchema\JsonSchema $schema): array => $this->buildLaravelSchemaArray($schema, $definition);

            $tools[] = new class($toolName, $toolDescription, $handler, $schemaFn) implements \Laravel\Ai\Contracts\Tool
            {
                public function __construct(
                    private readonly string $toolName,
                    private readonly string $toolDescription,
                    private readonly \Closure $handler,
                    private readonly \Closure $schemaFn,
                ) {}

                public function name(): string
                {
                    return $this->toolName;
                }

                public function description(): string
                {
                    return $this->toolDescription;
                }

                /**
                 * @return array<string, \Illuminate\JsonSchema\Types\Type>
                 */
                public function schema(\Illuminate\Contracts\JsonSchema\JsonSchema $schema): array
                {
                    return ($this->schemaFn)($schema);
                }

                public function handle(\Laravel\Ai\Tools\Request $request): string
                {
                    return ($this->handler)($this->toolName, $request->all());
                }
            };
        }

        return $tools;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    protected function buildLaravelSchemaArray(\Illuminate\Contracts\JsonSchema\JsonSchema $schema, array $definition): array
    {
        $properties = data_get($definition, 'inputSchema.properties', []);
        $required = data_get($definition, 'inputSchema.required', []);
        $result = [];

        $definitionName = (string) data_get($definition, 'name', 'unknown');

        foreach ($properties as $name => $property) {
            $type = $this->buildLaravelTypeFromProperty($schema, $property);
            if (! $type instanceof \Illuminate\JsonSchema\Types\Type) {
                continue;
            }

            $type = $type->description($this->getParameterDescription((string) $name, $property, $definitionName));

            if (in_array($name, $required)) {
                $type = $type->required();
            }

            $result[$name] = $type;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $property
     */
    protected function buildLaravelTypeFromProperty(\Illuminate\Contracts\JsonSchema\JsonSchema $schema, array $property): ?\Illuminate\JsonSchema\Types\Type
    {
        if ($property === []) {
            return null;
        }

        $type = $property['type'] ?? null;

        if ($type === null && isset($property['anyOf'])) {
            // The Laravel AI SDK does not support union types; fall back to string.
            Log::warning('Relay: anyOf union type is not supported by the Laravel AI SDK; falling back to string.', [
                'server' => $this->serverName,
                'property' => $property,
            ]);

            return $schema->string();
        }

        return match ($type) {
            // In JSON Schema, enum values are a validation constraint on a type, not a type themselves.
            // A string property with enum looks like {"type": "string", "enum": ["a", "b"]}.
            'string' => isset($property['enum'])
                ? $schema->string()->enum($property['enum'])
                : $schema->string(),
            'number' => $schema->number(),
            'integer' => $schema->integer(),
            'boolean' => $schema->boolean(),
            'array' => $this->buildLaravelArrayType($schema, $property),
            'object' => $this->buildLaravelObjectType($schema, $property),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $property
     */
    protected function buildLaravelArrayType(\Illuminate\Contracts\JsonSchema\JsonSchema $schema, array $property): \Illuminate\JsonSchema\Types\ArrayType
    {
        $arrayType = $schema->array();

        $items = data_get($property, 'items', []);
        if ($items !== []) {
            $itemType = $this->buildLaravelTypeFromProperty($schema, $items);
            if ($itemType instanceof \Illuminate\JsonSchema\Types\Type) {
                $arrayType->items($itemType);
            }
        }

        return $arrayType;
    }

    /**
     * @param  array<string, mixed>  $property
     */
    protected function buildLaravelObjectType(\Illuminate\Contracts\JsonSchema\JsonSchema $schema, array $property): \Illuminate\JsonSchema\Types\ObjectType
    {
        $nestedProperties = [];
        $nestedRequired = data_get($property, 'required', []);

        foreach (data_get($property, 'properties', []) as $propName => $propDef) {
            $propType = $this->buildLaravelTypeFromProperty($schema, $propDef);
            if ($propType instanceof \Illuminate\JsonSchema\Types\Type) {
                if (in_array($propName, $nestedRequired)) {
                    $propType = $propType->required();
                }
                $nestedProperties[$propName] = $propType;
            }
        }

        $objectType = $schema->object($nestedProperties);

        if (data_get($property, 'allowAdditionalProperties', true) === false) {
            $objectType->withoutAdditionalProperties();
        }

        return $objectType;
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

        return function (...$args) use ($toolName, $definition): string {
            if ($args !== [] && array_keys($args) !== range(0, count($args) - 1)) {
                return $this->callMCPTool($toolName, $args);
            }

            if (count($args) === 1 && isset($args[0]) && is_array($args[0])) {
                return $this->callMCPTool($toolName, $args[0]);
            }

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
            $parameter = $this->getSchemeParameter((string) $name, $property, $definitionName);
            if ($parameter instanceof Schema) {
                $parameters[] = $parameter;
            }
        }

        return $parameters;
    }

    /**
     * @param  array<string, mixed>  $property
     *
     * @throws RelayException
     */
    protected function getSchemeParameter(string $name, array $property, string $definitionName): ?Schema
    {
        if ($property === []) {
            return null;
        }

        $type = data_get($property, 'type');
        $description = $this->getParameterDescription($name, $property, $definitionName);

        if ($type === null && isset($property['anyOf'])) {
            $type = 'anyOf';
            $itemsSchema = $this->getSchemeParameters(data_get($property, 'anyOf', []), $definitionName);

            return new AnyOfSchema($itemsSchema, $name, $description);
        }

        $itemsSchema = $this->getSchemeParameter('', data_get($property, 'items', []), $definitionName);

        return match ($type) {
            'string' => new StringSchema($name, $description),
            'number', 'integer' => new NumberSchema($name, $description),
            'boolean' => new BooleanSchema($name, $description),
            'enum' => new EnumSchema($name, $description, data_get($property, 'options', [])),
            'object' => new ObjectSchema($name, $description, $this->getSchemeParameters(data_get($property, 'properties', []), $definitionName), data_get($property, 'required', []), data_get($property, 'allowAdditionalProperties', false)),
            'array' => $itemsSchema instanceof Schema ? new ArraySchema($name, $description, $itemsSchema) : null,
            default => null,
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
     * @throws ToolCallException
     */
    protected function callMCPTool(string $toolName, mixed $parameters): string
    {
        try {
            $baseToolName = $this->extractBaseToolName($toolName);
            $normalizedParams = $this->normalizeParameters($parameters);

            $result = $this->executeMCPToolCall($baseToolName, $normalizedParams);

            return $this->formatToolResponse($result);
        } catch (\Throwable $e) {
            throw new ToolCallException($e->getMessage(), previous: $e);
        }
    }

    protected function extractBaseToolName(string $toolName): string
    {
        $prefix = "relay__{$this->serverName}__";

        if (str_starts_with($toolName, $prefix)) {
            return substr($toolName, strlen($prefix));
        }

        return $toolName;
    }

    /**
     * @return array<string, mixed>
     */
    protected function normalizeParameters(mixed $parameters): array
    {
        if (is_string($parameters)) {
            return $this->isUrlParameter($parameters)
                ? ['url' => $parameters]
                : ['text' => $parameters];
        }

        if (is_object($parameters)) {
            return (array) $parameters;
        }

        if (is_array($parameters)) {
            return $parameters;
        }

        return [];
    }

    protected function isUrlParameter(string $parameter): bool
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
        return $this->transport->sendRequest('tools/call', [
            'name' => $toolName,
            'arguments' => (object) $parameters,
        ]);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function formatToolResponse(array $response): string
    {
        if (isset($response['content']) && is_array($response['content'])) {
            return $this->formatContentResponse($response);
        }

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

        if ($texts !== []) {
            return $prefix.implode("\n", $texts);
        }

        if ($images !== []) {
            return $prefix.implode("\n", $images);
        }

        return $prefix.'Tool executed'.($prefix === '' ? ' successfully' : '');
    }

    protected function convertResponseToString(mixed $response): string
    {
        if (is_string($response)) {
            return $response;
        }

        if (is_scalar($response)) {
            return (string) $response;
        }

        if (is_object($response) && method_exists($response, '__toString')) {
            return (string) $response;
        }

        if (is_array($response) || is_object($response)) {
            $json = json_encode($response, JSON_PRETTY_PRINT);

            return $json !== false ? $json : 'Tool returned an unserializable result';
        }

        return 'Tool executed successfully';
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
