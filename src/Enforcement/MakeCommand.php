<?php

declare(strict_types=1);

namespace Tey\Standards\Enforcement;

use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use Tey\Standards\Patterns\TreeCatalog;

final class MakeCommand extends Command
{
    public $signature = 'standards:make {unit : page, action, capability-tool or settings-section; model, policy, job, command or notification through tey/mod} {name : StudlyCase name} {--flavor= : The leaf flavor, as standards:pattern prints it}';

    public $description = 'Scaffold conformant code for a pattern leaf';

    public function handle(Repository $config): int
    {
        $unit = (string) $this->argument('unit');
        $flavor = $this->option('flavor') ?? match ($unit) {
            'page' => 'index',
            'action' => 'plain',
            'capability-tool' => 'read',
            'settings-section' => 'form',
            default => 'plain',
        };

        try {
            $generators = InstalledVersions::isInstalled('tey/mod')
                ? array_values(array_filter(array_keys($this->getApplication()?->all() ?? []), fn (string $name): bool => str_starts_with($name, 'make:')))
                : [];
            $files = (new Maker($this->laravel->basePath(), TreeCatalog::shipped(), (array) $config->get('standards.make', []), $generators, fn (string $command, array $arguments): int => $this->call($command, $arguments)))
                ->make($unit, (string) $this->argument('name'), (string) $flavor);
        } catch (InvalidArgumentException $error) {
            $this->error($error->getMessage());

            return 2;
        }

        foreach ($files as $file) {
            $this->line('✓ '.$file);
        }

        $this->line('Next: php artisan standards:check --changed');

        return 0;
    }
}
