<?php

declare(strict_types=1);

namespace Tey\Standards\Preflight;

enum Level: string
{
    case Blocking = 'blocking';
    case Warning = 'warning';
}
