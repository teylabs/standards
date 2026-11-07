<?php

declare(strict_types=1);

namespace Tey\Standards\Telemetry;

use DateTimeImmutable;
use Tey\Standards\Enforcement\ChangedFiles;
use Tey\Standards\Patterns\PatternCatalog;
use Tey\Standards\Patterns\TreeCatalog;
use Throwable;

/**
 * Turns a gate run or a pattern lookup into a run-log record and appends it, when `standards.telemetry.first_pass` is on. Every public
 * method fails open: it never throws and never changes the run's exit code.
 */
final class Recorder
{
    public function __construct(
        private readonly string $base,
        private readonly bool $enabled,
        private readonly string $configurationFile = 'tests/Arch/configuration.php',
        /** @var array<string, string|false>|null */
        private readonly ?array $environment = null,
        private readonly ?PatternCatalog $catalog = null,
    ) {}

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function file(): string
    {
        return $this->base.'/'.RunLog::FILE;
    }

    /**
     * Logs a `standards:check` run: every changed file in the run's scope, with its content hash, mtime, candidate pattern leaves and its
     * findings split into those on changed lines (the ones first pass counts) and the rest.
     *
     * @param  array<string, mixed>  $report  the schema-1 report
     */
    public function check(array $report, string $ref): void
    {
        if (! $this->enabled) {
            return;
        }

        try {
            RunLog::append($this->file(), $this->runRecord($report, $ref));
        } catch (Throwable) {
            // Fail open.
        }
    }

    /**
     * The run record for a report, without writing it (CI writes it as an artifact).
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    public function runRecord(array $report, string $ref): array
    {
        $changed = ChangedFiles::since($this->base, $ref);
        $scope = is_array($report['paths'] ?? null) && ($report['scope'] ?? null) === 'file' ? $report['paths'] : null;
        $files = array_values(array_filter($changed, fn (string $file): bool => $scope === null || in_array($file, $scope, true)));

        return [
            'kind' => 'run',
            'ts' => self::now(),
            ...$this->context(),
            'scope' => $report['scope'] ?? null,
            'sha' => $report['sha'] ?? null,
            'exit' => $report['exit_code'] ?? null,
            // The gate could not run (a missing configuration, a crash): its files are not clean, so episodes skip this run.
            'gate_error' => array_filter((array) ($report['violations'] ?? []), fn (mixed $v): bool => is_array($v) && ($v['rule'] ?? null) === 'standards' && ($v['kind'] ?? null) === 'error') !== [],
            'files' => $this->files($files, Diff::lines($this->base, $files, Diff::mergeBase($this->base, $ref)), (array) ($report['violations'] ?? [])),
        ];
    }

    /**
     * Per-file entries for the run record.
     *
     * @param  list<string>  $files
     * @param  array<string, array{new: bool, lines: list<int>}>  $diff
     * @param  list<array<string, mixed>>  $violations
     * @return list<array<string, mixed>>
     */
    public function files(array $files, array $diff, array $violations): array
    {
        $roots = $this->classRoots();
        $entries = [];

        foreach ($files as $file) {
            $path = $this->base.'/'.$file;
            $entries[$file] = [
                'path' => $file,
                'hash' => is_file($path) ? self::hash($path) : null,
                'mtime' => is_file($path) ? (int) filemtime($path) : null,
                'new' => $diff[$file]['new'] ?? true,
                'changed_lines' => ($diff[$file]['new'] ?? true) && is_file($path) ? count(file($path) ?: []) : count($diff[$file]['lines'] ?? []),
                'patterns' => $this->patterns($file),
                'findings' => [],
                'off_diff' => 0,
            ];
        }

        foreach ($violations as $violation) {
            $file = self::pathOf((string) ($violation['target'] ?? ''), $roots);

            if (! isset($entries[$file])) {
                continue;
            }

            $line = isset($violation['line']) ? (int) $violation['line'] : null;
            // A finding counts when it sits on a changed line; one without a line counts only in a new file, so old debt is not charged.
            $counts = $entries[$file]['new'] || ($line !== null && in_array($line, $diff[$file]['lines'] ?? [], true));

            if ($counts) {
                $rule = (string) ($violation['rule'] ?? 'unknown');
                $entries[$file]['findings'][$rule] = ($entries[$file]['findings'][$rule] ?? 0) + 1;
            } else {
                $entries[$file]['off_diff']++;
            }
        }

        return array_values($entries);
    }

    /**
     * Logs a lookup: the pattern leaves it answered with and the paths it targets.
     *
     * @param  list<string>  $patterns
     * @param  list<string>  $paths
     */
    public function lookup(string $tool, array $patterns, array $paths = []): void
    {
        if (! $this->enabled) {
            return;
        }

        try {
            RunLog::append($this->file(), [
                'kind' => 'lookup',
                'ts' => self::now(),
                ...$this->context(),
                'tool' => $tool,
                'patterns' => array_values(array_unique(array_filter($patterns, 'is_string'))),
                'paths' => array_values(array_unique(array_filter($paths, 'is_string'))),
            ]);
        } catch (Throwable) {
            // Fail open.
        }
    }

    /**
     * The leaf ids a pattern command's result names: a leaf, a plan's units, or a question's candidates.
     *
     * @param  array<string, mixed>  $result
     * @return list<string>
     */
    public static function patternsOf(array $result): array
    {
        return array_values(array_unique(array_filter(match ($result['type'] ?? null) {
            'leaf' => [$result['id'] ?? null],
            'plan' => array_map(fn (mixed $unit): mixed => is_array($unit) ? ($unit['leaf'] ?? null) : null, (array) ($result['units'] ?? [])),
            'gaps' => array_map(fn (mixed $leaf): mixed => is_array($leaf) ? ($leaf['id'] ?? null) : null, (array) ($result['leaves'] ?? [])),
            'question' => array_map(fn (mixed $candidate): mixed => is_array($candidate) ? ($candidate['id'] ?? null) : null, (array) ($result['candidates'] ?? [])),
            default => [],
        }, 'is_string')));
    }

    /** @return array<string, mixed> */
    private function context(): array
    {
        return [
            'source' => Context::source($this->environment),
            'who' => Context::who($this->environment),
            'standards' => Context::version(),
            'ruleset' => Context::ruleset($this->base, $this->configurationFile),
        ];
    }

    /** @return list<string> */
    private function patterns(string $file): array
    {
        try {
            $catalog = $this->catalog ?? TreeCatalog::shipped();

            return $catalog instanceof TreeCatalog ? array_map(fn ($leaf): string => $leaf->id, $catalog->forPath($file)) : [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Class targets map to files through the app's composer.json PSR-4 roots. The configuration file is not required a second time: it
     * may declare functions, and loading it twice would be fatal.
     *
     * @return array<string, string> root => namespace
     */
    private function classRoots(): array
    {
        try {
            $composer = is_file($this->base.'/composer.json') ? json_decode((string) file_get_contents($this->base.'/composer.json'), true) : null;
            $roots = [];

            foreach (['autoload', 'autoload-dev'] as $section) {
                foreach ((array) ($composer[$section]['psr-4'] ?? []) as $namespace => $directories) {
                    foreach ((array) $directories as $directory) {
                        if (is_string($namespace) && is_string($directory)) {
                            $roots[rtrim($directory, '/')] = $namespace;
                        }
                    }
                }
            }

            return $roots;
        } catch (Throwable) {
            return [];
        }
    }

    /** @param array<string, string> $roots */
    private static function pathOf(string $target, array $roots): string
    {
        foreach ($roots as $root => $namespace) {
            $prefix = rtrim($namespace, '\\').'\\';

            if (str_starts_with($target, $prefix) && ! str_contains($target, '/')) {
                return rtrim($root, '/').'/'.str_replace('\\', '/', substr($target, strlen($prefix))).'.php';
            }
        }

        return (string) preg_replace('/[ :#].*$/', '', $target);
    }

    /** A short content hash with line endings normalised, so a CRLF checkout (git autocrlf on Windows) hashes like the LF commit. */
    public static function hash(string $path): string
    {
        return substr(hash('sha256', str_replace("\r\n", "\n", (string) file_get_contents($path))), 0, 16);
    }

    public static function now(): string
    {
        return (new DateTimeImmutable)->format('Y-m-d\TH:i:s.uP');
    }
}
