<?php

declare(strict_types=1);

namespace Tey\Standards\Preflight;

enum Level: string
{
    case Blocking = 'blocking';
    case Advisory = 'advisory';

    /** @deprecated 0.10.0 Use Level::Advisory; deploy logs flag any line that says "warning". */
    public const Warning = self::Advisory;
}
