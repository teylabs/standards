<?php

declare(strict_types=1);

namespace Tey\Standards\Preflight;

interface Check
{
    public function name(): string;

    public function level(): Level;

    public function run(): Result;

    public function needsSecrets(): bool;
}
