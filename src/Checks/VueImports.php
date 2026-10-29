<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

/**
 * The `.vue` import walker shared by ui.kit-shell (does a layout reach kit's Workspace Layout?) and the FormStatus exemption (does a page's
 * component tree render kit's `<FormStatus>`?). Static and regex-based: default and named imports from `@/` (Vite's resources/js alias) and
 * relative specifiers only; dynamic imports, globs, other aliases and re-exports through `.ts` modules are not followed.
 */
final class VueImports
{
    /**
     * Whether `$path`, or a `.vue` file it imports (at most `$levels` import levels below it), satisfies `$hit`.
     * `$follow` maps one import of a file to the project path to descend into, or null to stop there. A file already on the walk is not
     * entered again, so an import cycle ends the branch instead of recursing.
     *
     * @param  callable(string $path, string $source, array<string, array{0: string, 1: string}> $imports): bool  $hit
     * @param  callable(array{0: string, 1: string} $import, string $from): ?string  $follow
     * @param  array<string, true>  $visited
     */
    public static function reaches(string $base, string $path, int $levels, callable $hit, callable $follow, int $depth = 0, array $visited = []): bool
    {
        if ($depth > $levels || isset($visited[$path])) {
            return false;
        }
        $visited[$path] = true;
        $source = (string) @file_get_contents($base.'/'.$path);
        $imports = self::imports(self::code(self::scripts($source)));
        if ($hit($path, $source, $imports)) {
            return true;
        }
        foreach ($imports as $import) {
            $next = $follow($import, $path);
            if ($next !== null && self::reaches($base, $next, $levels, $hit, $follow, $depth + 1, $visited)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Default and named imports, local name => [specifier, imported name]. Type-only imports are left out.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function imports(string $code): array
    {
        $imports = [];
        preg_match_all('~\bimport\s+(?!type\b)(?:([A-Za-z_$][\w$]*)\s*,?\s*)?(?:\{([^}]*)\}\s*)?from\s*([\'"])([^\'"]+)\3~', $code, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            if (($match[1] ?? '') !== '') {
                $imports[$match[1]] = [$match[4], 'default'];
            }
            foreach (explode(',', $match[2] ?? '') as $named) {
                if (preg_match('~^\s*(?:type\s+)?([\w$]+)(?:\s+as\s+([\w$]+))?\s*$~', $named, $parts) && ! preg_match('~^\s*type\s~', $named)) {
                    $imports[$parts[2] ?? $parts[1]] = [$match[4], $parts[1]];
                }
            }
        }

        return $imports;
    }

    /** The contents of every `<script>` block in a `.vue` file. */
    public static function scripts(string $source): string
    {
        preg_match_all('~<script\b[^>]*>(.*?)</script\s*>~si', $source, $blocks);

        return implode("\n", $blocks[1]);
    }

    /** JavaScript with its comments blanked (and its string contents too, when asked); lengths and lines are kept. */
    public static function code(string $source, bool $strings = false): string
    {
        $out = $source;
        $length = strlen($source);
        for ($i = 0; $i < $length; $i++) {
            $char = $source[$i];
            if (in_array($char, ['"', "'", '`'], true)) {
                for ($j = $i + 1; $j < $length && $source[$j] !== $char; $j++) {
                    if ($source[$j] === '\\') {
                        $j++;
                    }
                }
                if ($strings) {
                    for ($k = $i + 1; $k < min($j, $length); $k++) {
                        $out[$k] = $source[$k] === "\n" ? "\n" : ' ';
                    }
                }
                $i = $j;
            } elseif ($char === '/' && ($source[$i + 1] ?? '') === '/') {
                for (; $i < $length && $source[$i] !== "\n"; $i++) {
                    $out[$i] = ' ';
                }
            } elseif ($char === '/' && ($source[$i + 1] ?? '') === '*') {
                $end = strpos($source, '*/', $i + 2);
                $end = $end === false ? $length : $end + 2;
                for (; $i < $end; $i++) {
                    $out[$i] = $source[$i] === "\n" ? "\n" : ' ';
                }
                $i--;
            }
        }

        return $out;
    }

    /** `@/x` (Vite's resources/js alias) and relative specifiers, as a project path; a bare one gets `.vue` when that file exists. Null for anything else. */
    public static function resolve(string $base, string $specifier, string $from): ?string
    {
        if (str_starts_with($specifier, '@/')) {
            $path = 'resources/js/'.substr($specifier, 2);
        } elseif (str_starts_with($specifier, './') || str_starts_with($specifier, '../')) {
            $path = dirname($from).'/'.$specifier;
        } else {
            return null;
        }
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                if ($parts === []) {
                    return null;
                }
                array_pop($parts);
            } elseif ($part !== '.' && $part !== '') {
                $parts[] = $part;
            }
        }
        $path = implode('/', $parts);
        if (! preg_match('~\.[a-z]+$~', $path) && is_file($base.'/'.$path.'.vue')) {
            $path .= '.vue';
        }

        return $path;
    }
}
