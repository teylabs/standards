<?php

namespace FixtureGood\Domain\Billing\Models;

use FixtureGood\Domain\Billing\Models\Concerns\HasPhotos;
use FixtureGood\UI\MediaContract;

class Gallery implements MediaContract
{
    use HasPhotos;
}
