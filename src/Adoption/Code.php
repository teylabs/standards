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

    /**
     * A Blade view with what is never markup blanked (offsets and newlines kept): `{{-- --}}` comments, `@verbatim` blocks, HTML
     * comments, `<?php ... ?>` blocks (a Volt component's class) and `@php ... @endphp` blocks. With $echoes, `{{ }}` and `{!! !!}`
     * echoes are blanked too, so a tag written inside one is not read as an element.
     */
    public static function bladeMarkup(string $source, bool $echoes = false): string
    {
        $patterns = ['~\{\{--.*?--\}\}~s', '~@verbatim\b.*?(?:@endverbatim\b|\z)~s', '~<!--.*?(?:-->|\z)~s', '~<\?(?:php\b|=).*?(?:\?>|\z)~s', '~@php\b(?!\s*\().*?(?:@endphp\b|\z)~s'];
        if ($echoes) {
            $patterns[] = '~\{\{.*?\}\}|\{!!.*?!!\}~s';
        }

        return (string) preg_replace_callback($patterns, fn (array $m): string => (string) preg_replace('~[^\n]~', ' ', $m[0]), $source);
    }

    /**
     * Element tags in a Blade view (bladeMarkup with echoes blanked): HTML tags and component tags (`<x-name>`, `<x-dir.name>`,
     * `<x-dir::name>`), with their offsets in the original source.
     *
     * @return list<array{tag: string, offset: int}>
     */
    public static function bladeTags(string $source): array
    {
        preg_match_all('~<([A-Za-z][\w.:-]*)~', self::bladeMarkup($source, true), $matches, PREG_OFFSET_CAPTURE);

        return array_map(fn (array $match): array => ['tag' => $match[0], 'offset' => $match[1] - 1], $matches[1]);
    }

    /** Whether a Blade tag matches a signal's tag as written: a component (`x-...`) exactly, an HTML tag in any case. */
    public static function bladeTagIs(string $tag, string $name): bool
    {
        return str_starts_with(strtolower($name), 'x-') ? $tag === $name : strtolower($tag) === strtolower($name);
    }

    /**
     * The static attributes of the Blade element starting at $offset (its `<`) in bladeMarkup($source), lower-cased name => value.
     * What Blade or Alpine decides at render time is left out, so it satisfies no `where` and triggers no `except`: a bound attribute
     * (`:type`, `x-bind:type`), a value holding an echo (`type="{{ $t }}"`) or a directive, a directive (`@class(...)`, `@style(...)`,
     * `@click`), and an echo standing alone (`{{ $attributes }}`, `{{ $attributes->merge([...]) }}`). The element ends at the first `>`
     * outside quotes, echoes and directive parentheses. The first static occurrence of a name wins.
     *
     * @return array<string, string>
     */
    public static function bladeAttributes(string $source, int $offset): array
    {
        $code = self::bladeMarkup($source);
        if (preg_match('~\G<[A-Za-z][\w.:-]*~', $code, $tag, 0, $offset) !== 1) {
            return [];
        }
        $length = strlen($code);
        $i = $offset + strlen($tag[0]);
        $attributes = [];
        $echoEnd = function (int $at) use ($code): ?int {
            foreach (['{{' => '}}', '{!!' => '!!}'] as $open => $close) {
                if (substr($code, $at, strlen($open)) === $open) {
                    $end = strpos($code, $close, $at + strlen($open));

                    return $end === false ? strlen($code) : $end + strlen($close);
                }
            }

            return null;
        };
        while ($i < $length) {
            if (ctype_space($code[$i]) || $code[$i] === '/') {
                $i++;

                continue;
            }
            if ($code[$i] === '>') {
                break;
            }
            if (($end = $echoEnd($i)) !== null) {
                $i = $end;

                continue;
            }
            if (preg_match('~\G[^\s"\'<>/=(]+~', $code, $name, 0, $i) !== 1) {
                $i++;

                continue;
            }
            $i += strlen($name[0]);
            $bound = preg_match('~\A(?::|@|#|v-|x-bind:|x-on:)~i', $name[0]) === 1;
            // A directive's arguments: @class([...]), @style([...]), @if(...).
            if ($name[0][0] === '@' && preg_match('~\G\s*\(~', $code, $paren, 0, $i) === 1) {
                $i = self::closing($code, $i + strlen($paren[0]) - 1);

                continue;
            }
            $value = '';
            $dynamic = false;
            if (preg_match('~\G\s*=\s*~', $code, $equals, 0, $i) === 1) {
                $i += strlen($equals[0]);
                $quote = $code[$i] ?? '';
                if ($quote === '"' || $quote === "'") {
                    $i++;
                    while ($i < $length && $code[$i] !== $quote) {
                        if (($end = $echoEnd($i)) !== null) {
                            $dynamic = true;
                            $value .= substr($code, $i, $end - $i);
                            $i = $end;

                            continue;
                        }
                        $value .= $code[$i++];
                    }
                    $i++;
                } elseif (preg_match('~\G[^\s"\'=<>`]+~', $code, $bare, 0, $i) === 1) {
                    $value = $bare[0];
                    $i += strlen($bare[0]);
                }
                $dynamic = $dynamic || preg_match('~@\w+\s*\(~', $value) === 1;
            }
            $key = strtolower($name[0]);
            if ($bound || $dynamic || array_key_exists($key, $attributes)) {
                continue;
            }
            $attributes[$key] = $value;
        }

        return $attributes;
    }

    /** The offset just past the bracket that closes the one at $open, skipping quoted strings. */
    private static function closing(string $code, int $open): int
    {
        $depth = 0;
        for ($i = $open, $length = strlen($code); $i < $length; $i++) {
            $char = $code[$i];
            if ($char === '"' || $char === "'") {
                $end = $i + 1;
                while ($end < $length && $code[$end] !== $char) {
                    $end += $code[$end] === '\\' ? 2 : 1;
                }
                $i = $end;
            } elseif ($char === '(' || $char === '[') {
                $depth++;
            } elseif (($char === ')' || $char === ']') && --$depth === 0) {
                return $i + 1;
            }
        }

        return $length;
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

    /**
     * The static attributes of the element starting at $offset (its `<`), lower-cased name => value (`''` for a bare attribute). Bound
     * (`:type`, `v-bind:type`, `v-bind="..."`), event and directive attributes are left out: their value is not known statically. Quoted
     * values may hold `>`; the element ends at the first unquoted `>`. The first static occurrence of a name wins.
     *
     * @return array<string, string>
     */
    public static function staticAttributes(string $source, int $offset): array
    {
        if (preg_match('~\G<[A-Za-z][\w.:-]*~', $source, $tag, 0, $offset) !== 1) {
            return [];
        }
        $attributes = [];
        $position = $offset + strlen($tag[0]);
        $pattern = '~\G\s*(?:([^\s"\'<>/=]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?|/)~';
        while (preg_match($pattern, $source, $match, PREG_UNMATCHED_AS_NULL, $position) === 1 && $match[0] !== '') {
            $position += strlen($match[0]);
            $name = $match[1] === null ? null : strtolower($match[1]);
            if ($name === null || preg_match('~\A(?::|@|#|v-)~', $name) === 1 || array_key_exists($name, $attributes)) {
                continue;
            }
            $attributes[$name] = $match[2] ?? $match[3] ?? $match[4] ?? '';
        }

        return $attributes;
    }

    /**
     * Whether an element's static attributes pass a template-tag signal's `where` (every named attribute is static with a listed value) and
     * `except` (no named static attribute has a listed value). A bound or missing attribute satisfies no `where` and triggers no `except`.
     * Values compare case-insensitively, as HTML reads `type`.
     *
     * @param  array<string, string>  $attributes
     * @param  array<string, list<string>>  $where
     * @param  array<string, list<string>>  $except
     */
    public static function attributesPass(array $attributes, array $where, array $except): bool
    {
        $listed = fn (string $name, array $values): bool => array_key_exists($name, $attributes)
            && in_array(strtolower($attributes[$name]), array_map('strtolower', $values), true);
        foreach ($where as $name => $values) {
            if (! $listed($name, $values)) {
                return false;
            }
        }
        foreach ($except as $name => $values) {
            if ($listed($name, $values)) {
                return false;
            }
        }

        return true;
    }

    /** The PascalCase form of a kebab-case tag (`primary-button` => `PrimaryButton`); a tag without `-` is returned as is. */
    public static function pascal(string $tag): string
    {
        return str_contains($tag, '-') ? str_replace(' ', '', ucwords(str_replace('-', ' ', $tag))) : $tag;
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
        $uses = array_map(fn (array $import): string => $import['class'], self::phpImports($code));
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

    /**
     * A PHP file's `use` imports (comments already removed), short name or alias => the imported FQCN and the statement's offset and
     * length. Group imports (`use A\{B, C}`), `use function` and `use const` are not read.
     *
     * @return array<string, array{class: string, offset: int, length: int, at: int}> `at` is the imported name's offset
     */
    public static function phpImports(string $code): array
    {
        $uses = [];
        preg_match_all('~^\s*use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?\s*;~m', $code, $imports, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($imports as $import) {
            $alias = isset($import[2]) && $import[2][1] >= 0 ? $import[2][0] : '';
            $uses[$alias !== '' ? $alias : substr((string) strrchr('\\'.$import[1][0], '\\'), 1)] = ['class' => ltrim($import[1][0], '\\'), 'offset' => $import[0][1], 'length' => strlen($import[0][0]), 'at' => $import[1][1]];
        }

        return $uses;
    }

    /**
     * References to one class in PHP source, comments ignored: the FQCN written out (a leading `\` optional) anywhere, which covers
     * `new X`, `X::method()`, `X::class`, type hints and string literals naming it; and, after a `use` import of the class (or of a
     * namespace above it), its short name or alias (`Pdf::loadView`, `new Engine`, `Facade\Pdf::x`). `->Name` and `::Name` (a member that
     * shares the name) and `$Name` are not references. Laravel facade aliases from config/app.php are not followed.
     *
     * @return array{references: list<int>, imports: list<int>} offsets of the references outside `use` statements, and of the imports
     */
    public static function classReferences(string $source, string $class): array
    {
        $class = ltrim($class, '\\');
        $code = self::withoutComments($source);
        $imports = self::phpImports($code);
        $inImport = function (int $offset) use ($imports): bool {
            foreach ($imports as $import) {
                if ($offset >= $import['offset'] && $offset < $import['offset'] + $import['length']) {
                    return true;
                }
            }

            return false;
        };
        $patterns = ['~(?<![\w\\\\$])\\\\?'.preg_quote($class, '~').'(?![\w\\\\])~'];
        $importOffsets = [];
        foreach ($imports as $name => $import) {
            if ($import['class'] === $class) {
                $patterns[] = '~(?<![\w\\\\$]|->|::)'.preg_quote($name, '~').'(?![\w\\\\])~';
                $importOffsets[] = $import['at'];
            } elseif (str_starts_with($class, $import['class'].'\\')) {
                $patterns[] = '~(?<![\w\\\\$]|->|::)'.preg_quote($name.substr($class, strlen($import['class'])), '~').'(?![\w\\\\])~';
                $importOffsets[] = $import['at'];
            }
        }
        $references = [];
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $code, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[0] as [, $offset]) {
                if (! $inImport($offset)) {
                    $references[$offset] = $offset;
                }
            }
        }
        sort($references);
        sort($importOffsets);

        return ['references' => array_values($references), 'imports' => $importOffsets];
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
