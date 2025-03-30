<?php

declare(strict_types=1);

namespace Prism\Relay;

use Prism\Prism\Tool;
use Prism\Relay\Exceptions\ServerConfigurationException;
use Prism\Relay\Exceptions\ToolDefinitionException;

class RelayFactory
{
    /**
     * @throws ServerConfigurationException
     */
    public function make(string $serverName): Relay
    {
        return new Relay($serverName);
    }

    /**
     * @return array<int, Tool>
     *
     * @throws ServerConfigurationException
     * @throws ToolDefinitionException
     */
    public function tools(string $serverName): array
    {
        return $this->make($serverName)->tools();
    }
}
