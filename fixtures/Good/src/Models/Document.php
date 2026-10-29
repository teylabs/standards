<?php

declare(strict_types=1);

namespace FixtureGood\Models;

use FixtureGood\Models\Concerns\Versioned;
use Illuminate\Database\Eloquent\Model;

final class Document extends Model
{
    use Versioned;
}
