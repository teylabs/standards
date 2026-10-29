<?php

declare(strict_types=1);

namespace FixtureGood\Models\Concerns;

// A stale-write guard: model events bump `version` on every save.
trait Versioned
{
}
