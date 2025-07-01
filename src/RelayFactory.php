<?php

declare(strict_types=1);

namespace Prism\Relay;

use Prism\Prism\Tool;
use Prism\Relay\Exceptions\ServerConfigurationException;
use Prism\Relay\Exceptions\ToolDefinitionException;

class RelayFactory
{
    /**
     * @param  array<string, mixed>  $environment
     *
     * @throws ServerConfigurationException
     */
    public function make(string $serverName, array $environment = []): Relay
    {
        return new Relay($serverName, $environment);
    }

    /**
     * @param  array<string, mixed>  $environment
     * @return array<int, Tool>
     *
     * @throws ServerConfigurationException
     * @throws ToolDefinitionException
     */
    public function tools(string $serverName, array $environment = []): array
    {
        return $this->make($serverName, $environment)->tools();
    }
}
