<?php

declare(strict_types=1);

namespace Tey\Standards\Enforcement;

use Illuminate\Console\Command;
use InvalidArgumentException;

final class InstallCommand extends Command
{
    public $signature = 'standards:install';

    public $description = 'Write the pattern index, agent skills, Claude hooks and pre-push hook into this app';

    public function handle(): int
    {
        try {
            foreach (Installer::shipped($this->laravel->basePath())->install() as $line) {
                $this->line('✓ '.$line);
            }
        } catch (InvalidArgumentException $error) {
            $this->error($error->getMessage());

            return 1;
        }

        $this->line('Run php artisan boost:install (or boost:update) to publish the .ai/skills into every agent.');

        return 0;
    }
}
