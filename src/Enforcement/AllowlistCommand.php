<?php

declare(strict_types=1);

namespace Tey\Standards\Enforcement;

use Illuminate\Console\Command;
use InvalidArgumentException;

final class AllowlistCommand extends Command
{
    public $signature = 'standards:allowlist {--stamp-expiry= : Give every entry without an `expires` this Y-m-d date; an existing expiry is never changed} {--file= : The allowlist file (default: the one tests/Arch/configuration.php requires, else tests/Arch/conformance-allowlist.php)} {--dry-run : Show what it would stamp and write nothing} {--json : Print the plan as JSON}';

    public $description = 'Maintain the conformance allowlist: date legacy entries in one go';

    public function handle(): int
    {
        $expires = $this->option('stamp-expiry');

        if (! is_string($expires) || $expires === '') {
            $this->error('Pass --stamp-expiry=YYYY-MM-DD.');

            return 2;
        }

        $stamper = new AllowlistStamper($this->laravel->basePath());
        $dryRun = (bool) $this->option('dry-run');

        try {
            $plan = $stamper->plan($expires, is_string($this->option('file')) ? $this->option('file') : null);

            if (! $dryRun && $plan['stamped'] !== []) {
                $stamper->apply($plan);
            }
        } catch (InvalidArgumentException $error) {
            if ($this->option('json')) {
                $this->line(json_encode(['dry_run' => $dryRun, 'error' => $error->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } else {
                $this->error($error->getMessage());
            }

            return 2;
        }

        if ($this->option('json')) {
            $this->line(json_encode(['dry_run' => $dryRun, 'file' => $plan['file'], 'expires' => $plan['expires'], 'stamped' => count($plan['stamped']), 'kept' => $plan['kept'], 'entries' => $plan['stamped'], 'skipped' => $plan['skipped']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return 0;
        }

        $this->line(sprintf('%s %d %s in %s with expires %s; %d already dated, unchanged.', $dryRun ? 'Would stamp' : 'Stamped', count($plan['stamped']), count($plan['stamped']) === 1 ? 'entry' : 'entries', $plan['file'], $plan['expires'], $plan['kept']));

        foreach ($plan['skipped'] as $entry) {
            $this->warn("  skipped [{$entry['rule']}] {$entry['target']}: {$entry['reason']}");
        }

        if ($dryRun) {
            $this->line('Dry run: nothing written.');
        }

        return 0;
    }
}
