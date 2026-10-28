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
    public $signature = 'standards:make {unit : crud, model, page, action, capability-tool, settings-section, command, webhook or api-client; policy, job or notification (and model --flavor=plain) through tey/mod} {name : StudlyCase name (crud: the singular record name)} {--flavor= : The leaf flavor, as standards:pattern prints it} {--leaf= : The leaf to stamp, when several leaves share this make command} {--bulk : crud: selectable rows and a bulk delete} {--mcp : crud: read and write capability tools} {--soft-deletes : crud or model: soft deletes (crud adds a restoration)} {--tenant : model: workspace-owned through tey/kit, with a stale-write guard and a policy} {--enum=* : model: field:Enum, a backed enum with a database check constraint (repeatable)} {--media : model: spatie/laravel-medialibrary attachments} {--sortable : model: a position column and an ordered scope} {--backfill : command: fill a column for rows that lack it (the default)} {--maintenance : command: delete stale rows} {--report : command: count, read-only} {--sdk : api-client: the official SDK behind the contract} {--http : api-client: Laravel\'s Http with timeout, retries and backoff (the default)}';

    public $description = 'Scaffold conformant code for a pattern leaf';

    public function handle(Repository $config): int
    {
        $unit = (string) $this->argument('unit');
        $chosen = array_keys(array_filter(['backfill' => $this->option('backfill'), 'maintenance' => $this->option('maintenance'), 'report' => $this->option('report')]));
        if (count($chosen) > 1 || ($chosen !== [] && ($unit !== 'command' || $this->option('flavor') !== null))) {
            $this->error('Choose one of --backfill, --maintenance or --report, for standards:make command only.');

            return 2;
        }
        $client = array_keys(array_filter(['sdk' => $this->option('sdk'), 'http' => $this->option('http')]));
        if (count($client) > 1 || ($client !== [] && ($unit !== 'api-client' || $this->option('flavor') !== null))) {
            $this->error('Choose --sdk or --http, for standards:make api-client only.');

            return 2;
        }
        $chosen = [...$chosen, ...$client];
        $flavor = $this->option('flavor') ?? $chosen[0] ?? match ($unit) {
            'command' => 'backfill',
            'webhook' => 'signed',
            'api-client' => 'http',
            'model' => 'domain',
            'page' => 'index',
            'action' => 'plain',
            'capability-tool' => 'read',
            'settings-section' => 'form',
            'crud' => 'resource',
            default => 'plain',
        };

        try {
            $generators = InstalledVersions::isInstalled('tey/mod')
                ? array_values(array_filter(array_keys($this->getApplication()?->all() ?? []), fn (string $name): bool => str_starts_with($name, 'make:')))
                : [];
            $files = (new Maker($this->laravel->basePath(), TreeCatalog::shipped(), (array) $config->get('standards.make', []), $generators, fn (string $command, array $arguments): int => $this->call($command, $arguments)))
                ->make($unit, (string) $this->argument('name'), (string) $flavor, $this->option('leaf'), [...array_keys(array_filter(['bulk' => $this->option('bulk'), 'mcp' => $this->option('mcp'), 'soft-deletes' => $this->option('soft-deletes'), 'tenant' => $this->option('tenant'), 'media' => $this->option('media'), 'sortable' => $this->option('sortable')])), ...array_map(fn (string $enum): string => 'enum='.$enum, (array) $this->option('enum'))]);
        } catch (InvalidArgumentException $error) {
            $this->error($error->getMessage());

            return 2;
        }

        foreach ($files as $file) {
            $this->line('✓ '.$file);
        }

        if (in_array('composer.json', $files, true)) {
            $this->line('composer.json now maps Database\\Factories\\ to database/factories/: run composer dump-autoload.');
        }
        $this->line('Next: php artisan standards:check --changed');

        return 0;
    }
}
