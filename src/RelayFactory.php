<?php

declare(strict_types=1);

namespace Prism\Relay;

use Prism\Relay\Exceptions\ServerConfigurationException;
use Prism\Relay\Exceptions\ToolDefinitionException;

class RelayFactory
{
    /**
     * @param  array<string, mixed>|null  $config
     *
     * @throws ServerConfigurationException
     */
    public function make(string $serverName, ?array $config = null): Relay
    {
        return new Relay($serverName, $config);
    }

    /**
     * @param  array<string, mixed>|null  $config
     * @return array<int, \Prism\Prism\Tool|\Laravel\Ai\Contracts\Tool>
     *
     * @throws ServerConfigurationException
     * @throws ToolDefinitionException
     */
    public function tools(string $serverName, ?array $config = null): array
    {
        return $this->make($serverName, $config)->tools();
    }

    public function withToken(string $token): RelayBuilder
    {
        return new RelayBuilder($token);
    }
}
