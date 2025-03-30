<?php

declare(strict_types=1);

namespace Prism\Relay\Transport;

use Prism\Relay\Exceptions\TransportException;

interface Transport
{
    /**
     * @throws TransportException
     */
    public function start(): void;

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws TransportException
     */
    public function sendRequest(string $method, array $params = []): array;

    /**
     * @throws TransportException
     */
    public function close(): void;
}
