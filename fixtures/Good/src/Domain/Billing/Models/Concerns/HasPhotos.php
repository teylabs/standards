<?php

namespace FixtureGood\Domain\Billing\Models\Concerns;

use FixtureGood\UI\MediaBehaviour;

/** A trait that brings in the media trait is not itself a media model: it cannot implement the contract, its users must. */
trait HasPhotos
{
    use MediaBehaviour;
}
