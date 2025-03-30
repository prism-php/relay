<?php

declare(strict_types=1);

use Prism\Relay\Enums\Transport;

return [
    /*
    |--------------------------------------------------------------------------
    | MCP Server Configurations
    |--------------------------------------------------------------------------
    |
    | Define your MCP server configurations here. Each server should have a
    | name as the key, and a configuration array with the appropriate settings.
    |
    | Available configuration options:
    | - transport: Transport::Http or Transport::Stdio (required)
    | - for Http transport:
    |   - url: The URL of the MCP server (required)
    |   - api_key: Optional API key for authentication
    |   - timeout: Request timeout in seconds (default: 30)
    | - for Stdio transport:
    |   - command: The command to execute (required)
    |   - timeout: Process timeout in seconds (default: 30)
    |
    | Example HTTP configuration:
    | 'my_server' => [
    |     'transport' => Transport::Http,
    |     'url' => 'http://localhost:8000/api',
    |     'api_key' => null,
    |     'timeout' => 30,
    | ]
    |
    | Example stdio configuration:
    | 'puppeteer' => [
    |     'transport' => Transport::Stdio,
    |     'command' => 'npx -y @modelcontextprotocol/server-puppeteer',
    |     'timeout' => 60,
    | ]
    |
    */
    'servers' => [
        'puppeteer' => [
            'transport' => Transport::Stdio,
            'command' => ['npx', '-y', '@modelcontextprotocol/server-puppeteer'],
            'timeout' => env('RELAY_PUPPETEER_SERVER_TIMEOUT', 60),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tool Definition Cache Duration
    |--------------------------------------------------------------------------
    |
    | This value determines how long (in minutes) the tool definitions fetched
    | from MCP servers will be cached. Set to 0 to disable caching entirely.
    |
    */
    'cache_duration' => env('RELAY_TOOLS_CACHE_DURATION', 60),
];
