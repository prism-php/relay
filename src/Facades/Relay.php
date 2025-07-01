<?php

declare(strict_types=1);

namespace Prism\Relay\Facades;

use Illuminate\Support\Facades\Facade;
use Prism\Prism\Tool;
use Prism\Relay\RelayFactory;

/**
 * @method static RelayFactory make(string $serverName, array $environment = [])
 * @method static array<int, Tool> tools(string $serverName, array $environment = [])
 *
 * @see RelayFactory
 */
class Relay extends Facade
{
    #[\Override]
    protected static function getFacadeAccessor(): string
    {
        return 'relay';
    }
}
