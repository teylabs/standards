<?php

namespace FixtureBad\Domain\Billing\Models;

use FixtureGood\UI\MediaBehaviour;

class Photo
{
    use MediaBehaviour;

    public function size(object $media): mixed
    {
        return getimagesize($media?->getPath('preview'));
    }
}
