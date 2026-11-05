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
 * Rule `no-pixel-pins`: a test asserts behaviour and relations, not the pixels of today's design (Jasper, 2026-11-04: "my concern is why these tests get
 * written in the first place. it's a continuous rinse and repeat of pruning brittle tests").
 *
 * In an app's tests (`tests/`, PHP and JS/TS), a statement is flagged when:
 * - it reads `getComputedStyle` and compares with a literal design value: a quoted px/rem/em size, a colour (`rgb(`, `oklch(`, `#hex`…), a font
 *   weight (`'600'`) or a font family (`'Inter, sans-serif'`);
 * - it measures an element's width or height (`getBoundingClientRect`, `offsetWidth`, `clientHeight`…) and compares it with a number above 2, the size
 *   of a rounding tolerance.
 *
 * Relations stay allowed: one box below another (`a.top >= b.bottom`), equal widths, a difference within a tolerance (`Math.abs(a - b) < 2`), and a
 * style compared with another element's. Existing offenders take dated allowlist entries (one per statement) while they are rewritten.
 */
final readonly class NoPixelPins
{
    private const ROOT = 'tests';

    private const SKIP = ['node_modules', 'vendor', '.git', 'Fixtures', 'fixtures', 'Screenshots'];

    private const HINT = 'Assert what the user gets (visible, in order, not overlapping, the same width as its neighbour, a class or data state) instead of a pinned pixel, colour or font value; design tokens change without the behaviour changing.';

    /** A literal design value next to a computed style. */
    private const STYLE_LITERAL = '/([\'"`])\s*-?\d+(?:\.\d+)?(?:px|rem|em)\s*\1|[\'"`](?:rgba?|hsla?|oklch|oklab|lab|lch|color)\(|([\'"`])#[0-9a-fA-F]{3,8}\2|([\'"`])[1-9]00\3|([\'"`])[^\'"`]*\b(?:sans-serif|serif|monospace|system-ui|ui-sans-serif|ui-monospace)\b[^\'"`]*\4/';

    /** A width or height measured from the layout. */
    private const SIZE = '/getBoundingClientRect|offset(?:Width|Height)|client(?:Width|Height)|scroll(?:Width|Height)/';

    private const SIZE_READ = '/\.(?:width|height)\b|offset(?:Width|Height)|client(?:Width|Height)|scroll(?:Width|Height)/';

    /** A comparison with a number: JS operators, or a Pest matcher. */
    private const NUMBER = '/(?:[<>]=?|={2,3}|!={1,2})\s*(-?\d+(?:\.\d+)?)\b|\btoBe(?:LessThan|GreaterThan|LessThanOrEqual|GreaterThanOrEqual)?\(\s*(-?\d+(?:\.\d+)?)\s*\)/';

    public function __construct(private Configuration $config) {}

    /** @return list<array{target: string, check: string, hint?: string}> */
    public function violations(): array
    {
        $composer = $this->composer();
        if (! isset($composer['require']['laravel/framework'])) {
            return [];
        }

        $violations = [];
        foreach ($this->files() as $path) {
            foreach ($this->statements((string) file_get_contents($this->config->base.'/'.$path)) as [$line, $statement]) {
                $check = $this->pinned($statement);
                if ($check !== null) {
                    // The target names the statement, not its line, so an allowlist entry survives edits elsewhere in the file (the line is in the hint).
                    $violations[] = ['target' => $path.'#'.substr(hash('sha256', (string) preg_replace('/\s+/', ' ', trim($statement))), 0, 12), 'check' => $check, 'hint' => 'line '.$line.': '.self::HINT];
                }
            }
        }

        return $violations;
    }

    /** Why a statement pins a design value, or null. */
    private function pinned(string $statement): ?string
    {
        if (str_contains($statement, 'getComputedStyle') && preg_match(self::STYLE_LITERAL, $statement) === 1) {
            return 'a computed style compared with a literal design value (size, colour or font)';
        }
        if (preg_match(self::SIZE, $statement) === 1 && preg_match(self::SIZE_READ, $statement) === 1 && preg_match_all(self::NUMBER, $statement, $numbers, PREG_SET_ORDER) > 0) {
            foreach ($numbers as $match) {
                $value = (float) (($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? '0'));
                if (abs($value) > 2) {
                    return 'an element\'s width or height compared with a fixed number of pixels';
                }
            }
        }

        return null;
    }

    /**
     * Logical statements with their first line: lines joined until one ends a statement (`;`, `{` or `}`), so a chained `expect(...)->...` spread over
     * several lines is read whole.
     *
     * @return list<array{0: int, 1: string}>
     */
    private function statements(string $code): array
    {
        $statements = [];
        $buffer = '';
        $start = 0;
        foreach (preg_split('/\R/', $code) ?: [] as $index => $line) {
            // A comment, a blank line or the opening tag is not a statement: it would join the code after it and move the reported line.
            if ($buffer === '' && preg_match('#^\s*(?://|\*|/\*|\#|<\?php\b|$)#', $line) === 1) {
                continue;
            }
            if ($buffer === '') {
                $start = $index + 1;
            }
            $buffer .= ' '.trim($line);
            if (preg_match('/[;{}]\s*$/', $line) === 1) {
                $statements[] = [$start, trim($buffer)];
                $buffer = '';
            }
        }
        if (trim($buffer) !== '') {
            $statements[] = [$start, trim($buffer)];
        }

        return $statements;
    }

    /** @return list<string> PHP and JS/TS files under tests/, relative to the base, in a stable order. */
    private function files(): array
    {
        $dir = $this->config->base.'/'.self::ROOT;
        if (! is_dir($dir)) {
            return [];
        }
        $paths = [];
        $tree = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            fn (SplFileInfo $file): bool => ! ($file->isDir() && in_array($file->getFilename(), self::SKIP, true)),
        );
        foreach (new RecursiveIteratorIterator($tree) as $file) {
            if ($file->isFile() && preg_match('/\.(php|js|cjs|mjs|ts|cts|mts)$/', $file->getFilename()) === 1) {
                $paths[] = str_replace('\\', '/', substr($file->getPathname(), strlen($this->config->base) + 1));
            }
        }
        sort($paths);

        return $paths;
    }

    /** @return array<string, mixed>|null */
    private function composer(): ?array
    {
        $file = $this->config->base.'/composer.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($data) ? $data : null;
    }
}
