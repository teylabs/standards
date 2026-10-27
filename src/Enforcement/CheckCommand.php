<?php

declare(strict_types=1);

namespace Tey\Standards\Enforcement;

use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Tey\Standards\Patterns\KitExports;
use Tey\Standards\Patterns\TreeCatalog;

final class CheckCommand extends Command
{
    public $signature = 'standards:check {--changed : Only report findings in files changed against the base ref} {--file=* : Only report findings in these project-relative files} {--base= : Ref --changed compares against (default standards.base_ref)} {--json : Write the schema-1 drift JSON to stdout}';

    public $description = 'Run every conformance rule and the allowlist; exit 1 on drift';

    public function handle(Repository $config): int
    {
        $base = $this->laravel->basePath();

        if (($warning = KitExports::mismatch($base)) !== null) {
            fwrite(STDERR, 'Warning: '.$warning.PHP_EOL);
        }
        $files = array_values(array_filter((array) $this->option('file'), fn (mixed $file): bool => is_string($file) && $file !== ''));

        if ($files !== []) {
            $scope = 'file';
            $paths = array_map(fn (string $file): string => (string) preg_replace('#^(\./)+#', '', str_replace('\\', '/', str_starts_with($file, $base.'/') ? substr($file, strlen($base) + 1) : $file)), $files);
        } elseif ($this->option('changed')) {
            $scope = 'changed';
            $paths = ChangedFiles::since($base, (string) ($this->option('base') ?? $config->get('standards.base_ref', 'origin/main')));
        } else {
            $scope = 'full';
            $paths = null;
        }

        $report = CheckReport::build(
            $base,
            (string) $config->get('standards.configuration', 'tests/Arch/configuration.php'),
            $paths,
            $scope,
            new DateTimeImmutable('today'),
            TreeCatalog::shipped(),
            (int) $config->get('standards.expiring_days', 14),
            ChangedFiles::sha($base),
        );

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $report['exit_code'];
        }

        foreach ($report['violations'] as $violation) {
            $this->line("<fg=red>✗</> [{$violation['rule']}] {$violation['kind']}: {$violation['target']} — {$violation['check']}");
            $this->line('    fix: '.$violation['hint']);
        }

        foreach ($report['allowlist']['expiring'] as $entry) {
            $this->line("<fg=yellow>!</> allowlist expires {$entry['expires']}: [{$entry['rule']}] {$entry['target']} — {$entry['check']}");
        }

        $this->line(sprintf('%s: %d findings, %d allowlisted (%d expiring), %d flagged pattern gaps, scope %s%s.',
            $report['exit_code'] === 0 ? 'standards:check passed' : 'standards:check found drift',
            count($report['violations']), $report['allowlist']['count'], count($report['allowlist']['expiring']), $report['gaps']['flagged'], $scope,
            $report['skipped'] === [] ? '' : '; not run: '.implode(', ', $report['skipped'])));

        return $report['exit_code'];
    }
}
