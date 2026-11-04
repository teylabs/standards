<?php

namespace FixtureBad\Support\Live;

use Inertia\Inertia;

/** Rule live.broadcast-now violation: a live prop on a queued (ShouldBroadcast) notice. */
final class BuildProps
{
    /** @return array<string, mixed> */
    public function toArray(string $key): array
    {
        return ['tiles' => Inertia::live(fn (): array => [], on: new BuildChanged($key))];
    }
}
