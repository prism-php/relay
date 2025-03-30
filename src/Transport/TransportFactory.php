<?php

declare(strict_types=1);

namespace Prism\Relay\Transport;

use Prism\Relay\Enums\Transport as TransportEnum;
use Prism\Relay\Exceptions\ServerConfigurationException;

class TransportFactory
{
    /**
     * @param  array<string, mixed>  $config
     *
     * @throws ServerConfigurationException
     */
    public static function create(TransportEnum $type, array $config): Transport
    {
        return match ($type) {
            TransportEnum::Http => new HttpTransport($config),
            TransportEnum::Stdio => new StdioTransport($config),
        };
    }
}
