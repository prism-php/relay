<?php

declare(strict_types=1);

namespace Prism\Relay\Facades;

use Illuminate\Support\Facades\Facade;
use Prism\Relay\RelayFactory;

/**
 * @method static \Prism\Relay\Relay make(string $serverName, array $config = null)
 * @method static array<int, \Prism\Prism\Tool|\Laravel\Ai\Contracts\Tool> tools(string $serverName, array $config = null)
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
