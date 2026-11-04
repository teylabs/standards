<?php

namespace FixtureGood\Support\Live;

use Inertia\Inertia;

/** Rule live.broadcast-now control: a live prop on a ShouldBroadcastNow notice. */
final class BuildProps
{
    /** @return array<string, mixed> */
    public function toArray(string $key): array
    {
        return ['tiles' => Inertia::live(fn (): array => [], on: new BuildChanged($key))];
    }
}
