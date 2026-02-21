![](assets/relay-banner.webp)

<p align="center">
    <a href="https://packagist.org/packages/prism-php/relay">
        <img src="https://poser.pugx.org/prism-php/relay/d/total.svg" alt="Total Downloads">
    </a>
    <a href="https://packagist.org/packages/prism-php/relay">
        <img src="https://poser.pugx.org/prism-php/relay/v/stable.svg" alt="Latest Stable Version">
    </a>
    <a href="https://packagist.org/packages/prism-php/relay">
        <img src="https://poser.pugx.org/prism-php/relay/license.svg" alt="License">
    </a>
</p>

# Relay

A seamless integration between [Prism](https://github.com/prism-php/prism) and Model Context Protocol (MCP) servers that empowers your AI applications with powerful, external tool capabilities.

## Installation

You can install the package via Composer:

```bash
composer require prism-php/relay
```

After installation, publish the configuration file:

```bash
php artisan vendor:publish --tag="relay-config"
```

## Configuration

The published config file (`config/relay.php`) is where you'll define your MCP server connections.

### Configuring Servers

You must define each MCP server explicitly in the config file. Each server needs a unique name and the appropriate configuration parameters:

```php
return [
    'servers' => [
        'puppeteer' => [
            'command' => ['npx', '-y', '@modelcontextprotocol/server-puppeteer'],
            'timeout' => 30,
            'env' => [],
            'transport' => \Prism\Relay\Enums\Transport::Stdio,
        ],
        'github' => [
            'url' => env('RELAY_GITHUB_SERVER_URL', 'http://localhost:8001/api'),
            'timeout' => 30,
            'transport' => \Prism\Relay\Enums\Transport::Http,
        ],
    ],
    'cache_duration' => env('RELAY_TOOLS_CACHE_DURATION', 60), // in minutes (0 to disable)
];
```

## Basic Usage

Here's how you can integrate MCP tools into your Prism agent:

```php
use Prism\Prism\Prism;
use Prism\Relay\Facades\Relay;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-7-sonnet-latest')
    ->withPrompt('Find information about Laravel on the web')
    ->withTools(Relay::tools('puppeteer'))
    ->asText();

return $response->text;
```

The agent can now use any tools provided by the Puppeteer MCP server, such as navigating to webpages, taking screenshots, clicking buttons, and more.

## Real-World Example

Here's a practical example of creating a Laravel command that uses MCP tools with Prism:

```php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Prism\Relay\Facades\Relay;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;
use Prism\Prism\Text\PendingRequest;

use function Laravel\Prompts\note;
use function Laravel\Prompts\textarea;

class MCP extends Command
{
    protected $signature = 'prism:mcp';

    public function handle()
    {
        $response = $this->agent(textarea('Prompt'))->asText();

        note($response->text);
    }

    protected function agent(string $prompt): PendingRequest
    {
        return Prism::text()
            ->using(Provider::Anthropic, 'claude-3-7-sonnet-latest')
            ->withSystemPrompt(view('prompts.nova-v2'))
            ->withPrompt($prompt)
            ->withTools([
                ...Relay::tools('puppeteer'),
            ])
            ->usingTopP(1)
            ->withMaxSteps(99)
            ->withMaxTokens(8192);
    }
}
```

This command creates an interactive CLI that lets you input prompts that will be sent to Claude. The agent can use Puppeteer tools to browse the web, complete tasks, and return the results.

## Transport Types

Relay supports multiple transport mechanisms:

### HTTP Transport

For MCP servers that communicate over HTTP:

```php
'github' => [
    'url' => env('RELAY_GITHUB_SERVER_URL', 'http://localhost:8000/api'),
    'api_key' => env('RELAY_GITHUB_SERVER_API_KEY'),
    'timeout' => 30,
    'transport' => Transport::Http,
    'headers' => [
        'User-Agent' => 'prism-php-relay/1.0',
    ]
],
```

#### OAuth / Bearer Token Authentication

The [MCP 2025-11-25 authorization spec](https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization) defines an OAuth 2.1 flow for HTTP-based transports. **Relay handles only the last step — attaching the token to every request.** Your application is responsible for running the full OAuth flow and supplying the resulting access token at runtime.

##### What your application must handle

Before calling `withToken()`, your code must:

1. **Discover the authorization server** — make an unauthenticated request to the MCP server and read the `WWW-Authenticate` header from the `401` response, or probe `/.well-known/oauth-protected-resource`. The response contains the authorization server URL.
2. **Run the OAuth 2.1 authorization code flow** — including PKCE (`S256` code challenge is required by the spec) and the `resource` parameter ([RFC 8707](https://www.rfc-editor.org/rfc/rfc8707.html)) identifying the MCP server.
3. **Exchange the code for a token** and store/refresh it as needed. Tokens are short-lived; implement refresh token rotation.

> [!NOTE]
> The spec covers this in detail: [MCP Authorization — 2025-11-25](https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization).

##### Passing the token to Relay

Once you have a valid Bearer token, pass it to Relay at request time. The runtime token takes priority over any static `api_key` set in config.

```php
use Prism\Relay\Facades\Relay;
use Prism\Relay\Exceptions\AuthorizationException;
use Prism\Relay\Exceptions\TransportException;

// Via the Facade (returns a RelayBuilder)
$tools = Relay::withToken($request->user()->mcp_token)->tools('github');

// Or on a Relay instance
$relay = Relay::make('github');
$tools = $relay->withToken($request->user()->mcp_token)->tools();
```

When the MCP server rejects the token with HTTP 401, Relay throws `AuthorizationException`. Use this to trigger a re-authorization flow in your app:

```php
try {
    $tools = Relay::withToken($token)->tools('github');
} catch (AuthorizationException $e) {
    // Token is missing, expired, or rejected (HTTP 401)
    // Re-run the OAuth flow to get a fresh token
    return redirect('/oauth/reconnect');
} catch (TransportException $e) {
    // Other transport failure (non-401)
    Log::error('MCP Transport error: ' . $e->getMessage());
}
```

> [!NOTE]
> OAuth tokens are only supported with HTTP transport. Passing a token to a Stdio-configured server throws a `ServerConfigurationException`. For Stdio servers, provide credentials via the `env` config key instead.

### STDIO Transport

For locally running MCP servers that communicate via standard I/O:

```php
'puppeteer' => [
    'command' => [
      'npx',
      '-y',
      '@modelcontextprotocol/server-puppeteer',
      '--options',
      // Array values are passed as JSON encoded strings
      [
        'debug' => env('MCP_PUPPETEER_DEBUG', false)
      ]
    ],
    'timeout' => 30,
    'transport' => Transport::Stdio,
    'env' => [
        'NODE_ENV' => 'production',  // Set Node environment
        'MCP_SERVER_PORT' => '3001',  // Set a custom port for the server
    ],
],
```

> [!NOTE]
> The STDIO transport launches a subprocess and communicates with it through standard input/output. This is perfect for running tools directly on your application server.

> [!TIP]
> The `env` option allows you to pass environment variables to the MCP server process. This is useful for configuring server behavior, enabling debugging, or setting authentication details.

## Advanced Usage

### Using Multiple MCP Servers

You can combine tools from multiple MCP servers in a single Prism agent:

```php
use Prism\Prism\Prism;
use Prism\Relay\Facades\Relay;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-7-sonnet-latest')
    ->withTools([
        ...Relay::tools('github'),
        ...Relay::tools('puppeteer')
    ])
    ->withPrompt('Find and take screenshots of Laravel repositories')
    ->asText();
```

### Error Handling

The package uses specific exception types for better error handling:

```php
use Prism\Relay\Exceptions\AuthorizationException;
use Prism\Relay\Exceptions\RelayException;
use Prism\Relay\Exceptions\ServerConfigurationException;
use Prism\Relay\Exceptions\ToolCallException;
use Prism\Relay\Exceptions\ToolDefinitionException;
use Prism\Relay\Exceptions\TransportException;

try {
    $tools = Relay::tools('puppeteer');
    // Use the tools...
} catch (ServerConfigurationException $e) {
    // Handle configuration errors (missing server, invalid settings)
    Log::error('MCP Server configuration error: ' . $e->getMessage());
} catch (ToolDefinitionException $e) {
    // Handle issues with tool definitions from the MCP server
    Log::error('MCP Tool definition error: ' . $e->getMessage());
} catch (AuthorizationException $e) {
    // Handle HTTP 401 — token is missing, expired, or invalid
    return redirect('/oauth/reconnect');
} catch (TransportException $e) {
    // Handle communication errors with the MCP server
    Log::error('MCP Transport error: ' . $e->getMessage());
} catch (ToolCallException $e) {
    // Handle errors when calling a specific tool
    Log::error('MCP Tool call error: ' . $e->getMessage());
} catch (RelayException $e) {
    // Handle any other MCP-related errors
    Log::error('Relay general error: ' . $e->getMessage());
}
```

## License

The MIT License (MIT). Please see the [License File](LICENSE) for more information.
