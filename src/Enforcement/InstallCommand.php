<?php

declare(strict_types=1);

namespace Tey\Standards\Enforcement;

use Illuminate\Console\Command;
use InvalidArgumentException;

final class InstallCommand extends Command
{
    public $signature = 'standards:install {--extract : Move a Configuration built inline in an Arch test into tests/Arch/configuration.php, and make the test require it; installs nothing else} {--dry-run : With --extract, show the files it would write and write nothing} {--json : With --extract, print the plan as JSON}';

    public $description = 'Write the pattern index, agent skills, Claude hooks and pre-push hook into this app';

    public function handle(): int
    {
        if ($this->option('extract')) {
            return $this->extract();
        }

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

    /** The migration path for an app that builds its Configuration inline (the older pattern): one file in, one file rewritten, nothing overwritten. */
    private function extract(): int
    {
        $extractor = new Extractor($this->laravel->basePath());
        $dryRun = (bool) $this->option('dry-run');

        try {
            $plan = $extractor->plan();

            if (! $dryRun) {
                $extractor->apply($plan);
            }
        } catch (InvalidArgumentException $error) {
            if ($this->option('json')) {
                $this->line(json_encode(['dry_run' => $dryRun, 'extracted' => false, 'error' => $error->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } else {
                $this->error($error->getMessage());
            }

            return 2;
        }

        $files = [['path' => $plan['target'], 'action' => 'created'], ['path' => $plan['test'], 'action' => 'modified']];

        if ($this->option('json')) {
            $this->line(json_encode(['dry_run' => $dryRun, 'extracted' => ! $dryRun, 'from' => $plan['test'], 'carried' => $plan['carried'], 'files' => $files], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return 0;
        }

        foreach ($files as $file) {
            $this->line(($dryRun ? '· ' : '✓ ').$file['path'].' ('.$file['action'].')');
        }

        if ($plan['carried'] !== []) {
            $this->line('  carried with it: '.implode(', ', $plan['carried']));
        }

        $this->line($dryRun ? 'Dry run: nothing written.' : 'Run php artisan standards:check: it now reads the same Configuration as the Arch suite.');

        return 0;
    }
}
