<?php

declare(strict_types=1);

namespace Prism\Relay;

use Prism\Prism\Tool;

class RelayBuilder
{
    public function __construct(private readonly string $token) {}

    /**
     * @param  array<string, mixed>|null  $config
     *
     * @throws \Prism\Relay\Exceptions\ServerConfigurationException
     */
    public function make(string $serverName, ?array $config = null): Relay
    {
        return (new Relay($serverName, $config))->withToken($this->token);
    }

    /**
     * @param  array<string, mixed>|null  $config
     * @return array<int, Tool>
     *
     * @throws \Prism\Relay\Exceptions\ServerConfigurationException
     * @throws \Prism\Relay\Exceptions\ToolDefinitionException
     */
    public function tools(string $serverName, ?array $config = null): array
    {
        return $this->make($serverName, $config)->tools();
    }
}
