<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Tey\Kit\Features\Workspaces\Concerns\HasWorkspaces;

final class Workspace extends Model
{
    use HasWorkspaces;
}
