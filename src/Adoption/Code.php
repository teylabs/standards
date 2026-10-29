<?php

declare(strict_types=1);

namespace Tey\Standards\Adoption;

/** Small static readers for the evidence standards:adoption looks for: JS imports, template tags and PHP class declarations. */
final class Code
{
    /**
     * Static `import ... from 'module'` and bare `import 'module'` statements (Vue `<script>` blocks included). Type-only imports count.
     *
     * @return list<array{module: string, named: list<string>, default: ?string, offset: int}> named holds the exported names
     */
    public static function imports(string $source): array
    {
        $imports = [];
        preg_match_all('~\bimport\s+(?:type\s+)?([^\'";]*?)\s*\bfrom\s*([\'"])([^\'"]+)\2|\bimport\s*([\'"])([^\'"]+)\4~', self::withoutComments($source), $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($matches as $match) {
            if (isset($match[5]) && $match[5][1] >= 0) {
                $imports[] = ['module' => $match[5][0], 'named' => [], 'default' => null, 'offset' => $match[0][1]];

                continue;
            }
            $clause = trim($match[1][0]);
            $named = [];
            if (preg_match('~\{([^}]*)\}~', $clause, $braces) === 1) {
                foreach (explode(',', $braces[1]) as $part) {
                    $name = trim((string) preg_replace('~^type\s+~', '', trim($part)));
                    $name = trim((string) preg_split('~\s+as\s+~', $name)[0]);
                    if ($name !== '') {
                        $named[] = $name;
                    }
                }
            }
            $default = preg_match('~\A([A-Za-z_$][\w$]*)\s*(?:,|\z)~', $clause, $first) === 1 ? $first[1] : null;
            $imports[] = ['module' => $match[3][0], 'named' => $named, 'default' => $default, 'offset' => $match[0][1]];
        }

        return $imports;
    }

    /**
     * Element tags in a Vue file's `<template>` (or a whole Blade/HTML file), comments removed, with their offsets in the original source.
     *
     * @return list<array{tag: string, offset: int}>
     */
    public static function tags(string $source, bool $vue = true): array
    {
        [$template, $start] = $vue ? self::template($source) : [$source, 0];
        $template = (string) preg_replace_callback('~<!--.*?-->~s', fn (array $m): string => str_repeat(' ', strlen($m[0])), $template);
        preg_match_all('~<([A-Za-z][\w.:-]*)~', $template, $matches, PREG_OFFSET_CAPTURE);

        return array_map(fn (array $match): array => ['tag' => $match[0], 'offset' => $start + $match[1] - 1], $matches[1]);
    }

    /** The first element inside a Vue file's top-level `<template>`, or null. */
    public static function root(string $source): ?array
    {
        $tags = self::tags($source);
        foreach ($tags as $tag) {
            if (! in_array(strtolower($tag['tag']), ['template', 'slot'], true)) {
                return $tag;
            }
        }

        return null;
    }

    /** Whether the element starting at $offset (its `<`) is an `<input>` with a static `type` of hidden, in any quoting or case. */
    public static function hiddenInput(string $source, int $offset): bool
    {
        $end = strpos($source, '>', $offset);
        $element = substr($source, $offset, $end === false ? null : $end - $offset);

        return preg_match('~\A<input\b~i', $element) === 1 && preg_match('~(?<![:\w-])type\s*=\s*(["\']?)hidden\1(?![\w-])~i', $element) === 1;
    }

    /** Whether a template tag matches a name: exact, or the kebab-case form of a PascalCase name. */
    public static function tagIs(string $tag, string $name): bool
    {
        return $tag === $name || ($name !== strtolower($name) && $tag === strtolower((string) preg_replace('~(?<!^)[A-Z]~', '-$0', $name)));
    }

    /**
     * PHP class declarations with their resolved parent and interfaces (via the file's namespace and `use` imports).
     *
     * @return list<array{name: string, class: string, extends: ?string, implements: list<string>, offset: int}>
     */
    public static function classes(string $source): array
    {
        $code = self::withoutComments($source);
        $namespace = preg_match('~^\s*namespace\s+([\w\\\\]+)\s*;~m', $code, $ns) === 1 ? $ns[1] : '';
        $uses = [];
        preg_match_all('~^\s*use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?\s*;~m', $code, $imports, PREG_SET_ORDER);
        foreach ($imports as $import) {
            $alias = $import[2] ?? '';
            $uses[$alias !== '' ? $alias : substr((string) strrchr('\\'.$import[1], '\\'), 1)] = ltrim($import[1], '\\');
        }
        $resolve = function (string $name) use ($namespace, $uses): string {
            if (str_starts_with($name, '\\')) {
                return ltrim($name, '\\');
            }
            $head = explode('\\', $name)[0];
            if (isset($uses[$head])) {
                return $uses[$head].substr($name, strlen($head));
            }

            return ltrim($namespace.'\\'.$name, '\\');
        };
        $classes = [];
        preg_match_all('~^\s*(?:(?:abstract|final|readonly)\s+)*class\s+(\w+)(?:\s+extends\s+([\w\\\\]+))?(?:\s+implements\s+([\w\\\\,\s]+?))?\s*\{~m', $code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($matches as $match) {
            $implements = isset($match[3]) && $match[3][1] >= 0 ? array_values(array_filter(array_map('trim', explode(',', $match[3][0])))) : [];
            $classes[] = [
                'name' => $match[1][0],
                'class' => ltrim($namespace.'\\'.$match[1][0], '\\'),
                'extends' => isset($match[2]) && $match[2][1] >= 0 && $match[2][0] !== '' ? $resolve($match[2][0]) : null,
                'implements' => array_map($resolve, $implements),
                'offset' => $match[0][1] + strlen($match[0][0]) - strlen(ltrim($match[0][0])),
            ];
        }

        return $classes;
    }

    /** Tables a migration creates with `Schema::create('<table>'` (never `Schema::table`, which alters). @return list<array{table: string, offset: int}> */
    public static function createdTables(string $source): array
    {
        preg_match_all('~Schema::(?:connection\([^)]*\)->)?create\(\s*([\'"])([\w.]+)\1~', self::withoutComments($source), $matches, PREG_OFFSET_CAPTURE);

        return array_map(fn (array $match): array => ['table' => $match[0], 'offset' => $match[1]], $matches[2]);
    }

    /** Blanks `//` and block comments (keeping offsets), leaving `://` in URLs alone. */
    public static function withoutComments(string $source): string
    {
        return (string) preg_replace_callback('~/\*.*?\*/|(?<![:\'"\w])//[^\n]*~s', fn (array $m): string => (string) preg_replace('~[^\n]~', ' ', $m[0]), $source);
    }

    /** @return array{0: string, 1: int} the top-level `<template>` body and its offset (the whole file when there is none) */
    private static function template(string $source): array
    {
        if (preg_match('~<template\b[^>]*>~', $source, $open, PREG_OFFSET_CAPTURE) !== 1) {
            return ['', 0];
        }
        $start = $open[0][1] + strlen($open[0][0]);
        $end = strrpos($source, '</template>');

        return [substr($source, $start, ($end === false || $end < $start ? strlen($source) : $end) - $start), $start];
    }
}
