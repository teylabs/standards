<?php

declare(strict_types=1);

namespace FixtureBad\Models;

use FixtureBad\Models\Concerns\Versioned;
use Illuminate\Database\Eloquent\Model;

final class Document extends Model
{
    use Versioned;
}
