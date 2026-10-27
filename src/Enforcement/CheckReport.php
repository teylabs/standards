<?php

declare(strict_types=1);

namespace Tey\Standards\Enforcement;

use DateTimeImmutable;
use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tey\Standards\Configuration;
use Tey\Standards\Patterns\Leaf;
use Tey\Standards\Patterns\PatternCatalog;
use Throwable;

/**
 * Builds the stable `standards:check --json` document (schema 1): app, sha,
 * scope, violations, allowlist count and expiries, pattern gaps and exit code.
 */
final class CheckReport
{
    /**
     * @param  list<string>|null  $paths
     * @return array<string, mixed>
     */
    public static function build(string $base, string $configurationFile, ?array $paths, string $scope, DateTimeImmutable $today, PatternCatalog $catalog, int $expiringDays = 14, ?string $sha = null, ?string $autoload = null): array
    {
        $allowlist = ['count' => 0, 'expiring' => [], 'expired' => []];
        $skipped = [];

        if ($paths === []) {
            $violations = [];
        } else {
            try {
                $configuration = self::configuration($base, $configurationFile);
                $result = (new Gate($configuration, $today, $catalog, $expiringDays, $autoload ?? $base.'/vendor/autoload.php'))->run($paths);
                ['violations' => $violations, 'allowlist' => $allowlist, 'skipped' => $skipped] = $result;
            } catch (Throwable $error) {
                $violations = [['rule' => 'standards', 'kind' => 'error', 'target' => $configurationFile, 'check' => $error->getMessage(), 'hint' => 'Create '.$configurationFile.' returning the app\'s Tey\Standards\Configuration, or set standards.configuration. The gate fails closed until it can run.']];
            }
        }

        return [
            'schema' => 1,
            'app' => self::app($base),
            'sha' => $sha,
            'generated_at' => $today->format('Y-m-d'),
            'scope' => $scope,
            'paths' => $paths,
            'violations' => $violations,
            'allowlist' => $allowlist,
            'skipped' => $skipped,
            'gaps' => [
                'catalog' => count($gapLeaves = array_filter($catalog->leaves(), fn (Leaf $leaf): bool => $leaf->status === 'gap')),
                'evidenced' => count(array_filter($gapLeaves, fn (Leaf $leaf): bool => $leaf->evidence !== null)),
                'flagged' => self::flaggedGaps($base),
                'demand' => GapDemand::counts($base),
            ],
            'exit_code' => $violations === [] ? 0 : 1,
        ];
    }

    public static function configuration(string $base, string $file): Configuration
    {
        $path = str_starts_with($file, '/') ? $file : $base.'/'.$file;

        if (! is_file($path)) {
            throw new \InvalidArgumentException("Conformance configuration {$file} not found.");
        }

        $configuration = (static fn (string $path): mixed => require $path)($path);

        if (! $configuration instanceof Configuration) {
            throw new \InvalidArgumentException("{$file} must return a Tey\\Standards\\Configuration.");
        }

        return $configuration;
    }

    private static function app(string $base): string
    {
        $composer = is_file($base.'/composer.json') ? json_decode((string) file_get_contents($base.'/composer.json'), true) : null;

        return is_array($composer) && is_string($composer['name'] ?? null) ? $composer['name'] : basename($base);
    }

    /** Files stamped `standards-pattern: gap`: built without a recipe and flagged for one. */
    private static function flaggedGaps(string $base): int
    {
        $count = 0;

        foreach (array_filter([$base.'/app', $base.'/resources/js'], 'is_dir') as $root) {
            $files = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                fn (SplFileInfo $file): bool => ! in_array($file->getFilename(), ['vendor', 'node_modules'], true),
            ));

            foreach ($files as $file) {
                $count += $file->isFile() && preg_match('/'.preg_quote(Hook::STAMP, '/').'\s*gap\b/', (string) file_get_contents($file->getPathname())) === 1 ? 1 : 0;
            }
        }

        return $count;
    }
}
