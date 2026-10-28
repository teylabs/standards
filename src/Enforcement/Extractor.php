<?php

declare(strict_types=1);

namespace Tey\Standards\Enforcement;

use InvalidArgumentException;

/**
 * Moves a Configuration built inline in an Arch conformance test (the older
 * pattern) into tests/Arch/configuration.php, so standards:check can read it,
 * and rewrites the test to require that file. The expression is copied as it
 * is, with the file's `use` imports and the top-level variables it reads, so
 * the Pest suite and the CLI gate see the same Configuration. Anything it can't
 * move safely is refused with the reason, and nothing is ever overwritten.
 */
final class Extractor
{
    public const TARGET = 'tests/Arch/configuration.php';

    public function __construct(private readonly string $base) {}

    /**
     * The test that builds a Configuration inline, project-relative, or null.
     * Only one: two would be ambiguous, so the first wins here and extract() refuses.
     */
    public function inlineTest(): ?string
    {
        foreach ($this->candidates() as $relative) {
            if ($this->inlineCount((string) file_get_contents($this->base.'/'.$relative)) > 0) {
                return $relative;
            }
        }

        return null;
    }

    /**
     * Plans the extraction, or refuses with the reason (InvalidArgumentException).
     *
     * @return array{test: string, target: string, configuration: string, rewritten: string, carried: list<string>}
     */
    public function plan(): array
    {
        if (is_file($this->base.'/'.self::TARGET)) {
            throw new InvalidArgumentException(self::TARGET.' already exists; nothing to extract (it is never overwritten).');
        }

        $test = $this->inlineTest() ?? throw new InvalidArgumentException('No Arch test builds a Configuration inline (new Configuration(...)); run php artisan standards:install to scaffold '.self::TARGET.' instead.');
        $source = (string) file_get_contents($this->base.'/'.$test);

        if ($this->inlineCount($source) !== 1) {
            throw new InvalidArgumentException($test.' builds more than one Configuration inline; move the one the gate should use into '.self::TARGET.' by hand.');
        }

        $tokens = token_get_all($source);
        [$start, $end] = $this->expression($tokens);
        $expression = self::text(array_slice($tokens, $start, $end - $start + 1));

        if (preg_match('/\$this\b/', $expression) === 1) {
            throw new InvalidArgumentException($test.': the inline Configuration uses $this, which a configuration file has no access to; move it by hand.');
        }

        if (dirname($test) !== dirname(self::TARGET) && preg_match('/__DIR__|__FILE__/', $expression) === 1) {
            throw new InvalidArgumentException($test.' is not in tests/Arch and its Configuration uses __DIR__ or __FILE__, whose meaning would change; move it by hand.');
        }

        [$assignments, $imports] = $this->topLevel($tokens, $start);
        $carried = $this->needed($expression, $assignments, $test);
        $statements = array_map(fn (string $name): string => $assignments[$name], $carried);
        $configuration = "<?php\n\ndeclare(strict_types=1);\n\n".($imports === [] ? '' : implode("\n", $imports)."\n\n")
            .'// Extracted by php artisan standards:install --extract from '.$test.", so standards:check and the Pest suite share one Configuration.\n"
            .($statements === [] ? '' : implode("\n", $statements)."\n\n")
            .'return '.$expression.";\n";
        $require = 'require __DIR__.\''.self::relative(dirname($test), self::TARGET).'\'';
        $rewritten = self::text(array_slice($tokens, 0, $start)).$require.self::text(array_slice($tokens, $end + 1));

        return ['test' => $test, 'target' => self::TARGET, 'configuration' => $configuration, 'rewritten' => $rewritten, 'carried' => array_map(fn (string $name): string => '$'.$name, $carried)];
    }

    /**
     * Writes the plan: the configuration file first (refusing if one appeared meanwhile), then the test.
     *
     * @param  array{test: string, target: string, configuration: string, rewritten: string, carried: list<string>}  $plan
     */
    public function apply(array $plan): void
    {
        $target = $this->base.'/'.$plan['target'];
        $handle = @fopen($target, 'x');

        if ($handle === false) {
            throw new InvalidArgumentException($plan['target'].' already exists; it is never overwritten.');
        }

        fwrite($handle, $plan['configuration']);
        fclose($handle);
        file_put_contents($this->base.'/'.$plan['test'], $plan['rewritten']);
    }

    /** @return list<string> project-relative PHP files under tests */
    private function candidates(): array
    {
        if (! is_dir($this->base.'/tests')) {
            return [];
        }

        $preferred = 'tests/Arch/ConformanceTest.php';
        $found = is_file($this->base.'/'.$preferred) ? [$preferred] : [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base.'/tests', \FilesystemIterator::SKIP_DOTS)) as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($this->base) + 1));

            if (str_ends_with($relative, '.php') && $relative !== $preferred && $relative !== self::TARGET && ! str_contains($relative, '/vendor/')) {
                $found[] = $relative;
            }
        }

        return $found;
    }

    private function inlineCount(string $source): int
    {
        $tokens = token_get_all($source);
        $count = 0;

        foreach ($tokens as $i => $token) {
            if (is_array($token) && $token[0] === T_NEW && $this->isConfiguration($tokens, $i)) {
                $count++;
            }
        }

        return $count;
    }

    /** @param  list<mixed>  $tokens */
    private function isConfiguration(array $tokens, int $new): bool
    {
        $j = $this->next($tokens, $new);
        $name = is_array($tokens[$j] ?? null) ? $tokens[$j][1] : '';

        return in_array(ltrim($name, '\\'), ['Configuration', 'Tey\\Standards\\Configuration'], true);
    }

    /**
     * The token span of the one `new Configuration(...)`, through its closing parenthesis.
     *
     * @param  list<mixed>  $tokens
     * @return array{0: int, 1: int}
     */
    private function expression(array $tokens): array
    {
        foreach ($tokens as $i => $token) {
            if (! is_array($token) || $token[0] !== T_NEW || ! $this->isConfiguration($tokens, $i)) {
                continue;
            }

            $open = $this->next($tokens, $this->next($tokens, $i));

            if (($tokens[$open] ?? null) !== '(') {
                break;
            }

            $depth = 0;

            for ($j = $open; $j < count($tokens); $j++) {
                $text = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                // `{` opens a closure body; curly-brace string interpolation tokens count the same.
                $depth += match (true) {
                    in_array($text, ['(', '[', '{'], true), is_array($tokens[$j]) && in_array($tokens[$j][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true) => 1,
                    in_array($text, [')', ']', '}'], true) => -1,
                    default => 0,
                };

                if ($depth === 0) {
                    return [$i, $j];
                }
            }
        }

        throw new InvalidArgumentException('The inline Configuration could not be read; move it into '.self::TARGET.' by hand.');
    }

    /**
     * Top-level `use` imports, and simple top-level `$name = ...;` assignments before the expression.
     *
     * @param  list<mixed>  $tokens
     * @return array{0: array<string, string>, 1: list<string>}
     */
    private function topLevel(array $tokens, int $until): array
    {
        $assignments = [];
        $imports = [];
        $depth = 0;
        $statement = [];

        for ($i = 0; $i < $until; $i++) {
            $token = $tokens[$i];
            $text = is_array($token) ? $token[1] : $token;

            if ($depth === 0 && $statement === [] && is_array($token) && in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $statement[] = $token;
            $depth += in_array($text, ['(', '[', '{'], true) || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) ? 1 : (in_array($text, [')', ']', '}'], true) ? -1 : 0);

            if ($depth === 0 && ($text === ';' || $text === '}')) {
                $code = trim(self::text($statement));
                $statement = [];

                if (preg_match('/^use\s+(?!function\b|const\b)[\w\\\\]+(\s+as\s+\w+)?\s*;$/', $code) === 1) {
                    $imports[] = $code;
                } elseif (preg_match('/^\$(\w+)\s*=(?!=)/', $code, $match) === 1 && $text === ';') {
                    $assignments[$match[1]] = $code;
                }
            }
        }

        return [$assignments, $imports];
    }

    /**
     * The top-level variables the expression reads, with what they read in turn, in file order.
     * A variable that is neither assigned at the top level nor bound inside a closure in the expression is refused.
     *
     * @param  array<string, string>  $assignments
     * @return list<string>
     */
    private function needed(string $expression, array $assignments, string $test): array
    {
        $bound = self::bound($expression);
        $needed = [];
        $queue = self::variables($expression);

        while ($queue !== []) {
            $name = array_shift($queue);

            if (isset($needed[$name]) || $name === 'this') {
                continue;
            }

            if (isset($assignments[$name])) {
                $needed[$name] = true;
                $queue = [...$queue, ...self::variables((string) preg_replace('/^\$\w+\s*=/', '', $assignments[$name]))];
            } elseif (! in_array($name, $bound, true)) {
                throw new InvalidArgumentException($test.': the inline Configuration reads $'.$name.', which is not a top-level assignment in that file; move it into '.self::TARGET.' by hand.');
            }
        }

        return array_values(array_filter(array_keys($assignments), fn (string $name): bool => isset($needed[$name])));
    }

    /** @return list<string> variable names read in the code */
    private static function variables(string $code): array
    {
        $names = [];

        foreach (token_get_all('<?php '.$code.';') as $token) {
            if (is_array($token) && $token[0] === T_VARIABLE) {
                $names[] = substr($token[1], 1);
            }
        }

        return array_values(array_unique($names));
    }

    /** @return list<string> names bound inside the code: closure and arrow function parameters, closure `use`, and variables assigned in it */
    private static function bound(string $code): array
    {
        preg_match_all('/\b(?:function|fn)\s*\(([^)]*)\)(?:\s*use\s*\(([^)]*)\))?/', $code, $closures, PREG_SET_ORDER);
        preg_match_all('/\$(\w+)\s*=(?![=>])/', $code, $assigned);
        $names = $assigned[1];

        foreach ($closures as $closure) {
            preg_match_all('/\$(\w+)/', $closure[1].' '.($closure[2] ?? ''), $parameters);
            $names = [...$names, ...$parameters[1]];
        }

        return array_values(array_unique($names));
    }

    /** @param  list<mixed>  $tokens */
    private function next(array $tokens, int $i): int
    {
        do {
            $i++;
        } while (isset($tokens[$i]) && is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true));

        return $i;
    }

    /** @param  list<mixed>  $tokens */
    private static function text(array $tokens): string
    {
        return implode('', array_map(fn (mixed $token): string => is_array($token) ? $token[1] : (string) $token, $tokens));
    }

    /** The path from one project-relative directory to a project-relative file, for `require __DIR__.'...'`. */
    private static function relative(string $from, string $to): string
    {
        $from = explode('/', trim($from, '/'));
        $to = explode('/', trim($to, '/'));

        while ($from !== [] && $to !== [] && $from[0] === $to[0] && count($to) > 1) {
            array_shift($from);
            array_shift($to);
        }

        return '/'.str_repeat('../', count($from)).implode('/', $to);
    }
}
