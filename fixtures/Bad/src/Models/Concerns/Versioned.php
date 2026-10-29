<?php

declare(strict_types=1);

namespace FixtureBad\Models\Concerns;

// A stale-write guard: model events bump `version` on every save.
trait Versioned
{
}
