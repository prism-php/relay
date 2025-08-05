<?php

declare(strict_types=1);

namespace Prism\Relay\Enums;

enum Transport: string
{
    case Http = 'http';
    case Stdio = 'stdio';
}
