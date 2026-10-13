<?php

declare(strict_types=1);

namespace Tey\Standards\Preflight;

enum Status: string
{
    case Pass = 'pass';
    case Fail = 'fail';
    case Warn = 'warn';
    case SkippedInCi = 'skipped-in-ci';
    case SkippedByRequest = 'skipped-by-request';
}
