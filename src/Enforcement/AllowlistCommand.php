<?php

declare(strict_types=1);

namespace Tey\Standards\Enforcement;

use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Tey\Standards\Patterns\TreeCatalog;
use InvalidArgumentException;

final class AllowlistCommand extends Command
{
    public $signature = 'standards:allowlist {--stamp-expiry= : Give every entry without an `expires` this Y-m-d date; an existing expiry is never changed} {--file= : The allowlist file (default: the one tests/Arch/configuration.php requires, else tests/Arch/conformance-allowlist.php)} {--baseline : Append every current finding of the --rule rules that the allowlist lacks, dated today and expiring --expires} {--rule=* : A rule to baseline (repeatable)} {--expires= : The Y-m-d expiry for baselined entries} {--reason= : The reason on baselined entries (default: a dated baseline note)} {--dry-run : Show what it would stamp or baseline and write nothing} {--json : Print the plan as JSON}';

    public $description = 'Maintain the conformance allowlist: date legacy entries in one go, or baseline a new rule\'s existing findings';

    public function handle(): int
    {
        if ($this->option('baseline')) {
            return $this->baseline($this->laravel->make(Repository::class));
        }

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

    private function baseline(Repository $config): int
    {
        $base = $this->laravel->basePath();
        $today = new DateTimeImmutable('today');
        $dryRun = (bool) $this->option('dry-run');
        $rules = array_values(array_filter((array) $this->option('rule'), fn (mixed $rule): bool => is_string($rule)));
        $reason = is_string($this->option('reason')) && $this->option('reason') !== '' ? $this->option('reason') : 'Baseline '.$today->format('Y-m-d').': existing findings when the rule was added; burn down by the expiry.';

        try {
            $report = CheckReport::build(
                $base,
                (string) $config->get('standards.configuration', 'tests/Arch/configuration.php'),
                null,
                'full',
                $today,
                TreeCatalog::shipped(),
                (int) $config->get('standards.expiring_days', 14),
                ChangedFiles::sha($base),
            );
            $findings = array_map(fn (array $finding): array => ['rule' => (string) $finding['rule'], 'target' => (string) $finding['target'], 'check' => (string) $finding['check']], [...$report['violations'], ...$report['advisories']]);
            $file = (new AllowlistStamper($base))->locate(is_string($this->option('file')) ? $this->option('file') : null);
            $plan = AllowlistBaseliner::plan($base, $file, $findings, $rules, (string) $this->option('expires'), $reason, $today);

            if (! $dryRun && $plan['added'] !== []) {
                AllowlistBaseliner::apply($base, $plan);
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
            $this->line(json_encode(['dry_run' => $dryRun, 'file' => $plan['file'], 'rules' => $plan['rules'], 'expires' => $plan['expires'], 'added' => count($plan['added']), 'kept' => $plan['kept'], 'entries' => $plan['added']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return 0;
        }

        $this->line(sprintf('%s %d %s for [%s] in %s, expiring %s; %d already listed, unchanged.', $dryRun ? 'Would baseline' : 'Baselined', count($plan['added']), count($plan['added']) === 1 ? 'finding' : 'findings', implode(', ', $plan['rules']), $plan['file'], $plan['expires'], $plan['kept']));

        if ($dryRun) {
            $this->line('Dry run: nothing written.');
        }

        return 0;
    }
}
