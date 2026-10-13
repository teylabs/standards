<?php

declare(strict_types=1);

namespace Tey\Standards\Preflight;

final readonly class Result
{
    public function __construct(public Status $status, public string $message) {}

    public static function pass(string $message): self
    {
        return new self(Status::Pass, $message);
    }

    public static function fail(string $message): self
    {
        return new self(Status::Fail, $message);
    }

    public static function warn(string $message): self
    {
        return new self(Status::Warn, $message);
    }
}
