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
 * Rule `browser.accessible`: a browser test that opens a page also checks it with axe. In an app's `tests/`, a PHP file that calls Pest's `visit(`
 * is flagged unless it also calls `assertAccessible(` (the starter's helper in tests/Pest.php: WCAG 2.2 AA, every impact level, dated exceptions)
 * or Pest's own `assertNoAccessibilityIssues(`. Lexical and per file; advisory until an app lists the rule in Configuration::$optionalRules.
 */
final readonly class BrowserAccessible
{
    private const ROOT = 'tests';

    private const SKIP = ['node_modules', 'vendor', '.git', 'Fixtures', 'fixtures', 'Screenshots'];

    private const HINT = 'Call assertAccessible($page, \'what the page shows\') on each page the test visits (the starter\'s helper in tests/Pest.php: axe, WCAG 2.2 AA, every impact level). A violation that cannot be fixed yet goes in AXE_EXCEPTIONS with a reason and an expiry date.';

    public function __construct(private Configuration $config) {}

    /** @return list<array{target: string, check: string, hint: string}> */
    public function violations(): array
    {
        $dir = $this->config->base.'/'.self::ROOT;
        if (! is_dir($dir)) {
            return [];
        }
        $tree = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            fn (SplFileInfo $file): bool => ! ($file->isDir() && in_array($file->getFilename(), self::SKIP, true)),
        );
        $violations = [];
        foreach (new RecursiveIteratorIterator($tree) as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }
            // Comments don't count: a commented-out visit() is not a test, and a commented-out check is not a check.
            $code = $this->code((string) file_get_contents($file->getPathname()));
            if (preg_match('/(?<![\w$>:])visit\s*\(/', $code) === 1 && preg_match('/\b(?:assertAccessible|assertNoAccessibilityIssues)\s*\(/', $code) !== 1) {
                $violations[] = [
                    'target' => str_replace('\\', '/', substr($file->getPathname(), strlen($this->config->base) + 1)),
                    'check' => 'a browser test visits a page without checking its accessibility',
                    'hint' => self::HINT,
                ];
            }
        }
        usort($violations, fn (array $a, array $b): int => strcmp($a['target'], $b['target']));

        return $violations;
    }

    private function code(string $source): string
    {
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }
}
