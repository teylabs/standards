<?php

namespace App\Console;

use Illuminate\Console\Command;

final class FirstOwnerCommand extends Command
{
    protected $signature = 'owner:first';

    public function handle(): int
    {
        return 0;
    }
}
