<?php

declare(strict_types=1);

namespace Tey\Standards;

/**
 * The one shared exclusion every file-scanning rule applies: build output and what the app's git ignores.
 *
 * Always skipped, git or not: public/build/ (Vite output) and public/hot.
 * In a git work tree (whose repository does not ignore the base itself), a path is skipped when `git check-ignore` reports it (all .gitignore files, .git/info/exclude
 * and core.excludesFile). check-ignore consults the index, so a tracked file under an ignored pattern is still scanned.
 * Without git (or if git fails), only the root .gitignore is read, statically: blank lines, comments, `!` negation,
 * trailing `/` (directories only), leading or inner `/` (anchored), `*`, `?`, `[...]` and `**`. Nested .gitignore
 * files, .git/info/exclude, global excludes and tracked-file knowledge are not available there.
 * Any doubt keeps the file: nothing else is ever skipped.
 */
final class Ignored
{
    /** Vite build output and its dev-server marker. */
    public const ALWAYS = ['public/build', 'public/hot'];

    /** @var array<string, ?bool> base => is a git work tree (null: not yet known) */
    private static array $git = [];

    /** @var array<string, list<array{regex: string, negate: bool, directory: bool}>> */
    private static array $rules = [];

    public static function always(string $path): bool
    {
        $path = self::normalise($path);
        foreach (self::ALWAYS as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The given base-relative paths with every excluded one removed, order kept.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    public static function filter(string $base, array $paths): array
    {
        $paths = array_values(array_filter(array_map(self::normalise(...), $paths), fn (string $path): bool => ! self::always($path)));
        if ($paths === []) {
            return [];
        }
        $ignored = self::git($base, $paths) ?? array_filter($paths, fn (string $path): bool => self::statically($base, $path));
        $ignored = array_flip(array_values($ignored));

        return array_values(array_filter($paths, fn (string $path): bool => ! isset($ignored[$path])));
    }

    /**
     * Keys of a path => contents map, filtered the same way.
     *
     * @template T
     *
     * @param  array<string, T>  $files
     * @return array<string, T>
     */
    public static function filterKeys(string $base, array $files): array
    {
        return array_intersect_key($files, array_flip(self::filter($base, array_keys($files))));
    }

    public static function flush(): void
    {
        self::$git = [];
        self::$rules = [];
    }

    private static function normalise(string $path): string
    {
        return ltrim(str_replace('\\', '/', $path), '/');
    }

    /**
     * The ignored paths per git, or null when base is not a git work tree or git cannot answer.
     *
     * @param  list<string>  $paths
     * @return ?list<string>
     */
    private static function git(string $base, array $paths): ?array
    {
        if (! function_exists('proc_open')) {
            return null;
        }
        if (! array_key_exists($base, self::$git)) {
            [$exit, $output] = self::run($base, ['rev-parse', '--is-inside-work-tree'], '');
            // A base that its enclosing repository itself ignores (an app tree inside a parent's ignored cache) would have every path
            // reported ignored: git is not asked there, and the base's own root .gitignore is read statically instead.
            self::$git[$base] = $exit === 0 && trim($output) === 'true' && self::run($base, ['check-ignore', '-q', '.'], '')[0] === 1;
        }
        if (! self::$git[$base]) {
            return null;
        }
        [$exit, $output] = self::run($base, ['check-ignore', '--stdin', '-z'], implode("\0", $paths)."\0");
        // 0: some ignored, 1: none ignored; anything else is an error, answered statically instead.
        if ($exit === 1) {
            return [];
        }
        if ($exit !== 0) {
            return null;
        }

        return array_values(array_filter(array_map(self::normalise(...), explode("\0", $output)), fn (string $path): bool => $path !== ''));
    }

    /**
     * @param  list<string>  $arguments
     * @return array{int, string}
     */
    private static function run(string $base, array $arguments, string $input): array
    {
        if (! is_dir($base)) {
            return [-1, ''];
        }
        // stdin and stdout go through temp files so a large path list can never deadlock the pipes.
        $in = tmpfile();
        $out = tmpfile();
        if ($in === false || $out === false) {
            return [-1, ''];
        }
        fwrite($in, $input);
        rewind($in);
        $process = @proc_open(['git', '-C', $base, '-c', 'core.quotePath=false', ...$arguments], [0 => $in, 1 => $out, 2 => ['file', DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null', 'w']], $pipes);
        if (! is_resource($process)) {
            return [-1, ''];
        }
        $exit = proc_close($process);
        rewind($out);

        return [$exit, (string) stream_get_contents($out)];
    }

    private static function statically(string $base, string $path): bool
    {
        $rules = self::$rules[$base] ??= self::parse($base.'/.gitignore');
        if ($rules === []) {
            return false;
        }
        $segments = explode('/', $path);
        // Git never re-includes a file under an ignored directory, so the first ignored ancestor decides.
        for ($i = 1; $i <= count($segments); $i++) {
            $prefix = implode('/', array_slice($segments, 0, $i));
            $directory = $i < count($segments);
            $ignored = false;
            foreach ($rules as $rule) {
                if (($directory || ! $rule['directory']) && preg_match($rule['regex'], $prefix) === 1) {
                    $ignored = ! $rule['negate'];
                }
            }
            if ($ignored) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{regex: string, negate: bool, directory: bool}> */
    private static function parse(string $file): array
    {
        if (! is_file($file) || ! is_readable($file)) {
            return [];
        }
        $rules = [];
        foreach (preg_split('/\r?\n/', (string) file_get_contents($file)) ?: [] as $line) {
            $line = rtrim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $negate = str_starts_with($line, '!');
            $line = $negate ? substr($line, 1) : ltrim(str_starts_with($line, '\\') ? substr($line, 1) : $line);
            $directory = str_ends_with($line, '/');
            $line = rtrim($line, '/');
            if ($line === '') {
                continue;
            }
            $anchored = str_contains($line, '/');
            $line = ltrim($line, '/');
            $body = self::glob($line);
            $rules[] = ['regex' => '~^'.($anchored ? '' : '(?:.*/)?').$body.'$~', 'negate' => $negate, 'directory' => $directory];
        }

        return $rules;
    }

    private static function glob(string $pattern): string
    {
        $regex = '';
        $length = strlen($pattern);
        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];
            if (substr($pattern, $i, 3) === '**/') {
                $regex .= '(?:.*/)?';
                $i += 2;
            } elseif (substr($pattern, $i, 2) === '**') {
                $regex .= '.*';
                $i++;
            } elseif ($char === '*') {
                $regex .= '[^/]*';
            } elseif ($char === '?') {
                $regex .= '[^/]';
            } elseif ($char === '[' && ($end = strpos($pattern, ']', $i + 1)) !== false) {
                $class = substr($pattern, $i + 1, $end - $i - 1);
                $regex .= '['.(str_starts_with($class, '!') ? '^'.substr($class, 1) : $class).']';
                $i = $end;
            } else {
                $regex .= preg_quote($char, '~');
            }
        }

        return $regex;
    }
}
