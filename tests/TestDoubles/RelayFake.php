<?php

declare(strict_types=1);

namespace Tests\TestDoubles;

use Prism\Relay\Relay;
use Prism\Relay\Transport\Transport;

class RelayFake extends Relay
{
    protected ?Transport $transportOverride = null;

    /**
     * @var array<int, mixed>
     */
    protected array $toolDefinitions = [];

    protected bool $shouldThrowOnTools = false;

    protected string $toolsExceptionMessage = 'Failed to fetch tools';

    /**
     * Sets a custom transport for testing purposes.
     *
     * @throws \Prism\Relay\Exceptions\ServerConfigurationException
     */
    public function setTransport(Transport $transport): self
    {
        $this->transportOverride = $transport;
        $this->transport = $transport;

        return $this;
    }

    /**
     * Sets custom tool definitions for testing.
     *
     * @param  array<int, mixed>  $toolDefinitions
     */
    public function setToolDefinitions(array $toolDefinitions): self
    {
        $this->toolDefinitions = $toolDefinitions;

        return $this;
    }

    /**
     * Configures the fake to throw an exception when fetching tools.
     */
    public function shouldThrowOnTools(string $message = 'Failed to fetch tools'): self
    {
        $this->shouldThrowOnTools = true;
        $this->toolsExceptionMessage = $message;

        return $this;
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws \Prism\Relay\Exceptions\ToolDefinitionException
     */
    #[\Override]
    protected function fetchToolDefinitions(): array
    {
        if ($this->shouldThrowOnTools) {
            throw new \Prism\Relay\Exceptions\ToolDefinitionException($this->toolsExceptionMessage);
        }

        if ($this->toolDefinitions !== []) {
            return $this->toolDefinitions;
        }

        // Default tool definitions for testing
        return [
            [
                'name' => 'test_tool',
                'description' => 'A test tool for testing',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'param1' => [
                            'type' => 'string',
                            'description' => 'Parameter 1',
                        ],
                        'param2' => [
                            'type' => 'number',
                            'description' => 'Parameter 2',
                        ],
                        'param3' => [
                            'type' => 'boolean',
                            'description' => 'Parameter 3',
                        ],
                    ],
                    'required' => ['param1'],
                ],
            ],
            [
                'name' => 'url_tool',
                'description' => 'A URL-based tool',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'url' => [
                            'type' => 'string',
                            'description' => 'URL to navigate to',
                        ],
                    ],
                    'required' => ['url'],
                ],
            ],
            [
                'name' => 'selector_tool',
                'description' => 'A selector-based tool',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'selector' => [
                            'type' => 'string',
                            'description' => 'CSS selector',
                        ],
                    ],
                    'required' => ['selector'],
                ],
            ],
            [
                'name' => 'selector_value_tool',
                'description' => 'A selector and value based tool',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'selector' => [
                            'type' => 'string',
                            'description' => 'CSS selector',
                        ],
                        'value' => [
                            'type' => 'string',
                            'description' => 'Value to set',
                        ],
                    ],
                    'required' => ['selector', 'value'],
                ],
            ],
            [
                'name' => 'name_tool',
                'description' => 'A tool with name parameter',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => [
                            'type' => 'string',
                            'description' => 'Name parameter',
                        ],
                        'selector' => [
                            'type' => 'string',
                            'description' => 'Optional selector',
                        ],
                        'width' => [
                            'type' => 'number',
                            'description' => 'Optional width',
                        ],
                        'height' => [
                            'type' => 'number',
                            'description' => 'Optional height',
                        ],
                    ],
                    'required' => ['name'],
                ],
            ],
            [
                'name' => 'script_tool',
                'description' => 'A tool with script parameter',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'script' => [
                            'type' => 'string',
                            'description' => 'Script to execute',
                        ],
                    ],
                    'required' => ['script'],
                ],
            ],
            [
                'name' => 'union_tool',
                'description' => 'A tool with union parameter',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'nameOrId' => [
                            'anyOf' => [
                                [
                                    'type' => 'string',
                                    'description' => 'Name',
                                ],
                                [
                                    'type' => 'number',
                                    'description' => 'ID',
                                ],
                            ],
                        ],
                    ],
                    'required' => ['nameOrId'],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|object|string  $parameters
     */
    #[\Override]
    protected function callMCPTool(string $toolName, $parameters): string
    {
        // Return predictable results for testing
        if (is_string($parameters)) {
            return "Called {$toolName} with string parameter: {$parameters}";
        }

        if (is_object($parameters)) {
            $parameters = (array) $parameters;
        }

        $paramString = json_encode($parameters);

        return "Called {$toolName} with parameters: {$paramString}";
    }
}
