<?php

declare(strict_types=1);

namespace Tests\TestDoubles;

use Prism\Relay\Relay as BaseRelay;
use Prism\Relay\Transport\Transport;

class Relay extends BaseRelay
{
    public function __construct(string $serverName, protected Transport $testTransport)
    {
        parent::__construct($serverName);
    }

    #[\Override]
    protected function initializeTransport(): void
    {
        $this->transport = $this->testTransport;
    }
}
