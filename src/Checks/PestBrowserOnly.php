<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tey\Standards\Configuration;

/**
 * An app's browser tests run through Pest's browser plugin, not a Node suite (Jasper, 2026-10-13: "enforce pest5's browser testing across the
 * board"). Only apps are checked: a package with no laravel/framework (a component library) has no app for Pest to drive.
 *
 * The plain `playwright` package is allowed: pestphp/pest-plugin-browser drives the browser through it. What is flagged is a Node browser
 * suite: Playwright's own test runner (`@playwright/test`), Puppeteer or Cypress in package.json, their config files, and test scripts that
 * import any of them, `playwright` included, since Pest never needs a script of the app's own for that.
 */
final readonly class PestBrowserOnly
{
    /** npm packages that only a Node browser suite needs. */
    private const RUNNERS = ['@playwright/test', 'puppeteer', 'puppeteer-core', 'cypress'];

    /** Config files of those runners. */
    private const CONFIGS = '/^(playwright|cypress)\.config\.(js|cjs|mjs|ts|cts|mts)$/';

    /** Where Node test scripts live. */
    private const ROOTS = ['tests', 'e2e', 'browser', 'playwright', 'cypress'];

    private const SKIP = ['node_modules', 'vendor', '.git'];

    private const HINT = 'Write it as a Pest browser test (pestphp/pest-plugin-browser, `visit()`), then delete the Node suite. An existing suite takes a dated allowlist entry while the app moves over.';

    public function __construct(private Configuration $config) {}

    /** @return list<array{target: string, check: string, hint?: string}> */
    public function violations(): array
    {
        $composer = $this->json('composer.json');
        if (! isset($composer['require']['laravel/framework'])) {
            return [];
        }

        $violations = [];
        $npm = $this->json('package.json') ?? [];
        foreach (['dependencies', 'devDependencies'] as $section) {
            foreach (self::RUNNERS as $package) {
                if (isset($npm[$section][$package])) {
                    $violations[] = ['target' => 'package.json', 'check' => $package.' in '.$section.' runs a Node browser suite', 'hint' => self::HINT];
                }
            }
        }

        foreach (scandir($this->config->base) ?: [] as $name) {
            if (preg_match(self::CONFIGS, $name) === 1 && is_file($this->config->base.'/'.$name)) {
                $violations[] = ['target' => $name, 'check' => 'Node browser suite config', 'hint' => self::HINT];
            }
        }

        foreach ($this->scripts() as $path) {
            $code = (string) file_get_contents($this->config->base.'/'.$path);
            if (preg_match('/(?:\bfrom\s*|\brequire\s*\(\s*|\bimport\s*\(\s*)[\'"](@playwright\/test|playwright(?:-core)?|puppeteer(?:-core)?|cypress)[\'"]/', $code, $match) === 1) {
                $violations[] = ['target' => $path, 'check' => 'Node browser test imports '.$match[1], 'hint' => self::HINT];
            }
        }

        usort($violations, fn (array $a, array $b): int => [$a['target'], $a['check']] <=> [$b['target'], $b['check']]);

        return $violations;
    }

    /** @return list<string> JS and TS files under the test roots, relative to the base, in a stable order. */
    private function scripts(): array
    {
        $paths = [];
        foreach (self::ROOTS as $root) {
            $dir = $this->config->base.'/'.$root;
            if (! is_dir($dir)) {
                continue;
            }
            $tree = new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                fn (SplFileInfo $file): bool => ! ($file->isDir() && in_array($file->getFilename(), self::SKIP, true)),
            );
            foreach (new RecursiveIteratorIterator($tree) as $file) {
                if ($file->isFile() && preg_match('/\.(js|cjs|mjs|ts|cts|mts)$/', $file->getFilename()) === 1) {
                    $paths[] = substr($file->getPathname(), strlen($this->config->base) + 1);
                }
            }
        }
        sort($paths);

        return array_values(array_unique($paths));
    }

    /** @return array<string, mixed>|null */
    private function json(string $path): ?array
    {
        $file = $this->config->base.'/'.$path;
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($data) ? $data : null;
    }
}
