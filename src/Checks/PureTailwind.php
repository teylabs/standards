<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use Tey\Standards\Ignored;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use Tey\Standards\Configuration;
use Tey\Standards\Source;

/**
 * Rule `styling.pure-tailwind`: the app styles with Tailwind utilities and `@theme` tokens, not with its own CSS.
 *
 * - A `.vue` file has no `<style>` block.
 * - A `.vue` or Blade template has no `style="..."` attribute. A `:style` (`v-bind:style`) binding is allowed only as an object literal whose
 *   keys are all CSS custom properties (`:style="{ '--progress': value }"`), the documented way to hand a server value to a Tailwind class
 *   (`w-(--progress)`). Blade has no binding, so there a `style` attribute passes only when every declaration is a custom property.
 * - A `.css` file holds only Tailwind's directives (`@import`, `@theme`, `@source`, `@plugin`, `@utility`, `@layer`, plus `@config`,
 *   `@reference`, `@custom-variant`, `@tailwind`, `@charset`) in any argument form (quoted or not, with or without the semicolon,
 *   globs such as `{js,vue}`), `@font-face`, and custom-property declarations on `:root` and `.dark`. Inside
 *   `@layer` an element (base) rule passes and a class rule fails. `@media`, `@supports` and `@container` are read through.
 *
 * Not scanned: files tey/kit owns (KitOwnership), Filament theme CSS (under a `css/filament/` folder, or importing Filament's vendor theme),
 * published package views under `resources/views/vendor/`. A file that cannot be read or parsed is a finding, never a pass.
 */
final class PureTailwind
{
    public const RULE = 'styling.pure-tailwind';

    private const STATEMENTS = ['import', 'source', 'plugin', 'config', 'reference', 'custom-variant', 'layer', 'tailwind', 'charset'];

    /** Directives that are only ever statements: their arguments (globs with `{a,b}`, `/*`, quoted or not) never open a block. */
    private const STATEMENT_ONLY = ['import', 'source', 'config', 'reference', 'tailwind', 'charset', 'plugin'];

    private const OPAQUE = ['theme', 'utility', 'font-face', 'custom-variant'];

    private const TRANSPARENT = ['media', 'supports', 'container'];

    private const HINT_CLASSES = 'Use Tailwind utility classes in the template; put design tokens in `@theme`; hand a dynamic value through a custom property (`:style="{ \'--x\': value }"` with `w-(--x)`).';

    /** @return list<array{target: string, check: string, line?: int, hint?: string}> */
    public function violations(Configuration $config): array
    {
        $kit = KitOwnership::of($config);
        $findings = [];

        foreach ($this->files($config) as $path => $source) {
            if ($source === null) {
                $findings[] = ['target' => $path, 'check' => 'could not read the file', 'hint' => 'The rule fails closed: make the file readable so it can be checked.'];

                continue;
            }
            if ($kit->owns($path, $source)) {
                continue;
            }
            $found = match (true) {
                str_ends_with($path, '.vue') => self::vue($source),
                str_ends_with($path, '.blade.php') => self::blade($source),
                default => self::isFilamentTheme($path, $source) ? [] : self::css($source),
            };
            foreach ($found as $finding) {
                $findings[] = ['target' => $path, ...$finding];
            }
        }

        return $findings;
    }

    /**
     * Every candidate file, path => contents, or null when it cannot be read.
     *
     * @return array<string, ?string>
     */
    private function files(Configuration $config): array
    {
        $frontend = $config->frontendRoots ?? ['resources/js', ...$config->uiRoots];
        $sets = [
            '*.vue' => $frontend,
            '*.blade.php' => ['resources/views', ...$config->sourceRoots, ...$config->uiRoots, ...$frontend],
            '*.css' => ['resources/css', ...$config->sourceRoots, ...$frontend],
        ];
        $files = [];

        foreach ($sets as $pattern => $roots) {
            foreach (array_unique($roots) as $root) {
                foreach (glob($config->base.'/'.$root, GLOB_ONLYDIR) ?: [] as $directory) {
                    foreach ((new Finder)->files()->in($directory)->name($pattern)->exclude(['vendor', 'node_modules'])->ignoreDotFiles(false) as $file) {
                        $path = str_replace('\\', '/', substr($file->getPathname(), strlen($config->base) + 1));
                        if (str_starts_with($path, Source::GENERATED) || str_starts_with($path, 'resources/views/vendor/') || isset($files[$path])) {
                            continue;
                        }
                        $contents = is_readable($file->getPathname()) ? @file_get_contents($file->getPathname()) : false;
                        $files[$path] = $contents === false ? null : $contents;
                    }
                }
            }
        }

        $files = Ignored::filterKeys($config->base, $files);
        ksort($files);

        return $files;
    }

    public static function isFilamentTheme(string $path, string $source): bool
    {
        return str_contains('/'.$path, '/css/filament/') || preg_match('~@import\s+(?:url\()?\s*[\'"][^\'"]*vendor/filament/~', $source) === 1;
    }

    /** @return list<array{check: string, line: int, hint: string}> */
    public static function vue(string $source): array
    {
        try {
            $blank = self::blank($source, '~<!--.*?(?:-->|\z)~s', '<!--', '-->');
            $blank = self::blank($blank, '~<script\b[^>]*>.*?(?:</script\s*>|\z)~si', '<script', '</script', true);
        } catch (RuntimeException $error) {
            return [self::unparsed($source, $error)];
        }
        $findings = [];
        preg_match_all('~<style\b[^>]*>~i', $blank, $styles, PREG_OFFSET_CAPTURE);
        foreach ($styles[0] as [$tag, $offset]) {
            $findings[] = ['check' => 'style block in a .vue file', 'line' => self::line($source, $offset), 'hint' => 'Delete the <style> block. '.self::HINT_CLASSES];
        }
        try {
            $template = self::blank($blank, '~<style\b[^>]*>.*?(?:</style\s*>|\z)~si', '<style', '</style', true);

            return [...$findings, ...self::attributes($source, $template, blade: false)];
        } catch (RuntimeException $error) {
            return [...$findings, self::unparsed($source, $error)];
        }
    }

    /** @return list<array{check: string, line: int, hint: string}> */
    public static function blade(string $source): array
    {
        try {
            $blank = self::blank($source, '~\{\{--.*?(?:--\}\}|\z)~s', '{{--', '--}}');
            $blank = self::blank($blank, '~<!--.*?(?:-->|\z)~s', '<!--', '-->');
            $blank = self::blank($blank, '~<(script|style)\b[^>]*>.*?(?:</\1\s*>|\z)~si', '<script', '</', true);

            return self::attributes($source, $blank, blade: true);
        } catch (RuntimeException $error) {
            return [self::unparsed($source, $error)];
        }
    }

    /** @return list<array{check: string, line: int, hint: string}> */
    private static function attributes(string $source, string $template, bool $blade): array
    {
        $findings = [];
        $length = strlen($template);

        for ($i = 0; $i < $length; $i++) {
            if ($template[$i] !== '<' || ! preg_match('~\G<([A-Za-z][\w.:-]*)~', $template, $name, 0, $i)) {
                continue;
            }
            $start = $i + strlen($name[0]);
            $quote = null;
            for ($j = $start; $j < $length; $j++) {
                $char = $template[$j];
                if ($quote !== null) {
                    $quote = $char === $quote ? null : $quote;
                } elseif ($char === '"' || $char === "'") {
                    $quote = $char;
                } elseif ($char === '>') {
                    break;
                }
            }
            if ($j >= $length) {
                throw new RuntimeException('unterminated <'.$name[1].'> tag at line '.self::line($source, $i));
            }
            $body = substr($template, $start, $j - $start);
            preg_match_all('~(?<=^|[\s/])((?:v-bind)?:?[\w@#.:\[\]-]+)(?:\s*=\s*("[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+))?~', $body, $attributes, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($attributes as $attribute) {
                $attr = strtolower(preg_replace('~\.(?:prop|camel|attr)$~', '', $attribute[1][0]) ?? '');
                $value = isset($attribute[2]) ? trim($attribute[2][0], '"\'') : '';
                $line = self::line($source, $start + $attribute[1][1]);
                if ($attr === 'style' && ! ($blade && self::customPropertiesOnly($value))) {
                    $findings[] = ['check' => 'inline style attribute', 'line' => $line, 'hint' => 'Replace the style attribute with Tailwind classes. '.self::HINT_CLASSES];
                } elseif (in_array($attr, [':style', 'v-bind:style'], true) && ! $blade && ! self::bindsCustomPropertiesOnly($value)) {
                    $findings[] = ['check' => ':style binds a CSS property other than a custom property', 'line' => $line, 'hint' => 'Bind only custom properties, as an object literal (`:style="{ \'--x\': value }"`), and read them in a Tailwind class (`w-(--x)`). Static styling belongs in classes.'];
                }
            }
            $i = $j;
        }

        return $findings;
    }

    /** `--a: 1; --b: {{ $x }}`: every non-empty declaration is a custom property. */
    private static function customPropertiesOnly(string $value): bool
    {
        $declarations = array_filter(array_map('trim', explode(';', $value)), fn (string $part): bool => $part !== '');

        return $declarations !== [] && array_filter($declarations, fn (string $part): bool => preg_match('~^--[\w-]+\s*:~', $part) !== 1) === [];
    }

    /**
     * `{ '--a': x, "--b": y }`, or a ternary (nested too) whose every branch is one of those, `{}`, `undefined` or `null`.
     * Any other expression, or any other branch (a variable, a call), could set a real property.
     */
    private static function bindsCustomPropertiesOnly(string $expression): bool
    {
        $expression = self::unwrap($expression);
        if (in_array($expression, ['undefined', 'null'], true)) {
            return true;
        }
        $ternary = self::ternary($expression);
        if ($ternary !== null) {
            return self::bindsCustomPropertiesOnly($ternary[0]) && self::bindsCustomPropertiesOnly($ternary[1]);
        }
        if (! str_starts_with($expression, '{') || ! str_ends_with($expression, '}')) {
            return false;
        }
        $entries = self::splitTopLevel(substr($expression, 1, -1));
        if ($entries === null) {
            return false;
        }
        foreach ($entries as $entry) {
            if (trim($entry) !== '' && preg_match('~^\s*([\'"])--[\w-]+\1\s*:~', $entry) !== 1) {
                return false;
            }
        }

        return true;
    }

    /** The expression without whitespace and redundant outer parentheses. */
    private static function unwrap(string $expression): string
    {
        $expression = trim($expression);
        while (str_starts_with($expression, '(') && str_ends_with($expression, ')') && self::splitTopLevel(substr($expression, 1, -1)) !== null) {
            $expression = trim(substr($expression, 1, -1));
        }

        return $expression;
    }

    /**
     * The two branches of a top-level `condition ? a : b`, or null when the expression is not one (or does not balance).
     * `?.` and `??` are not ternaries; a nested ternary in the first branch is matched by counting.
     *
     * @return array{string, string}|null
     */
    private static function ternary(string $expression): ?array
    {
        $depth = 0;
        $quote = null;
        $question = null;
        $pending = 0;
        $length = strlen($expression);
        for ($i = 0; $i < $length; $i++) {
            $char = $expression[$i];
            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }
            if (in_array($char, ['"', "'", '`'], true)) {
                $quote = $char;
            } elseif (in_array($char, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($char, [')', ']', '}'], true)) {
                $depth--;
            } elseif ($depth === 0 && $char === '?') {
                $next = $expression[$i + 1] ?? '';
                if ($next === '?') {
                    $i++;
                } elseif ($next === '.' && ! ctype_digit($expression[$i + 2] ?? '')) {
                    $i++;
                } else {
                    $question ??= $i;
                    $pending++;
                }
            } elseif ($depth === 0 && $char === ':' && $question !== null && --$pending === 0) {
                return [substr($expression, $question + 1, $i - $question - 1), substr($expression, $i + 1)];
            }
        }

        return null;
    }

    /** @return list<string>|null the comma-separated parts at depth 0, or null when brackets or quotes do not balance */
    private static function splitTopLevel(string $source): ?array
    {
        $parts = [];
        $depth = 0;
        $quote = null;
        $current = '';
        $length = strlen($source);
        for ($i = 0; $i < $length; $i++) {
            $char = $source[$i];
            if ($quote !== null) {
                if ($char === '\\') {
                    $current .= $char.($source[++$i] ?? '');

                    continue;
                }
                $quote = $char === $quote ? null : $quote;
            } elseif (in_array($char, ['"', "'", '`'], true)) {
                $quote = $char;
            } elseif (in_array($char, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($char, [')', ']', '}'], true)) {
                if (--$depth < 0) {
                    return null;
                }
            } elseif ($char === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';

                continue;
            }
            $current .= $char;
        }

        return $quote === null && $depth === 0 ? [...$parts, $current] : null;
    }

    /** @return list<array{check: string, line: int, hint: string}> */
    public static function css(string $source): array
    {
        try {
            $blank = self::blank($source, '~("(?:[^"\\\\\n]|\\\\.)*"|\'(?:[^\'\\\\\n]|\\\\.)*\')|/\*.*?(?:\*/|\z)~s', '/*', '*/');

            return self::block($source, $blank, 0, strlen($blank), 'top');
        } catch (RuntimeException $error) {
            return [self::unparsed($source, $error)];
        }
    }

    /**
     * @param  'top'|'layer'|'tokens'  $context
     * @return list<array{check: string, line: int, hint: string}>
     */
    private static function block(string $source, string $css, int $from, int $to, string $context): array
    {
        $findings = [];
        $i = $from;

        while ($i < $to) {
            if ($context !== 'tokens' && preg_match('~\G\s*@('.implode('|', self::STATEMENT_ONLY).')(?![\w-])~i', $css, $directive, 0, $i)) {
                $i = self::statement($source, $css, $i + strlen($directive[0]), $to, strtolower($directive[1]) === 'plugin');

                continue;
            }
            [$prelude, $end, $terminator] = self::prelude($source, $css, $i, $to);
            $text = trim($prelude);
            $at = $i + (strlen($prelude) - strlen(ltrim($prelude)));
            $line = self::line($source, $at);
            if ($terminator === null && $text === '') {
                break;
            }
            if ($terminator === '}') {
                throw new RuntimeException('unexpected } at line '.self::line($source, $end));
            }
            // A last statement may end at the block's or the file's end without its semicolon.
            if ($terminator === ';' || $terminator === null) {
                $i = $end + 1;
                if ($text === '') {
                    continue;
                }
                if ($context === 'tokens') {
                    if (! preg_match('~^--[\w-]+\s*:~', $text)) {
                        $findings[] = ['check' => 'declaration other than a custom property on :root or .dark', 'line' => $line, 'hint' => 'Keep only `--token: value` declarations on :root and .dark, and style with Tailwind classes.'];
                    }

                    continue;
                }
                if (preg_match('~^@([\w-]+)~', $text, $name) && in_array(strtolower($name[1]), self::STATEMENTS, true)) {
                    continue;
                }
                $findings[] = str_starts_with($text, '@')
                    ? ['check' => 'unsupported at-rule '.strtok($text, " \t\n(;"), 'line' => $line, 'hint' => self::HINT_CLASSES]
                    : ['check' => 'declaration outside a rule', 'line' => $line, 'hint' => self::HINT_CLASSES];

                continue;
            }
            $close = self::matching($source, $css, $end, $to);
            $inner = [$end + 1, $close];
            $i = $close + 1;
            if ($context === 'tokens') {
                $findings[] = ['check' => 'nested rule inside :root or .dark', 'line' => $line, 'hint' => 'Keep only `--token: value` declarations on :root and .dark.'];

                continue;
            }
            if (str_starts_with($text, '@')) {
                $name = strtolower((string) preg_replace('~^@([\w-]+).*$~s', '$1', $text));
                if (in_array($name, self::OPAQUE, true)) {
                    continue;
                }
                if ($name === 'layer') {
                    $findings = [...$findings, ...self::block($source, $css, $inner[0], $inner[1], 'layer')];
                } elseif (in_array($name, self::TRANSPARENT, true)) {
                    $findings = [...$findings, ...self::block($source, $css, $inner[0], $inner[1], $context)];
                } else {
                    $findings[] = ['check' => 'at-rule @'.$name.' outside @theme', 'line' => $line, 'hint' => 'Move it into `@theme` (keyframes go there as `--animate-*` with their @keyframes) or replace it with Tailwind utilities.'];
                }

                continue;
            }
            $selector = (string) preg_replace('~\s+~', ' ', $text);
            if (self::tokenSelector($selector)) {
                $findings = [...$findings, ...self::block($source, $css, $inner[0], $inner[1], 'tokens')];
            } elseif ($context !== 'layer' || self::hasClass($selector)) {
                $findings[] = ['check' => 'custom CSS rule '.$selector, 'line' => $line, 'hint' => 'Delete the rule and use Tailwind utility classes (or `@utility` for a reusable one). '.self::HINT_CLASSES];
            }
        }

        return $findings;
    }

    /** @return array{0: string, 1: int, 2: ?string} prelude text, offset of its terminator, the terminator ({, ; or }) or null at the end */
    private static function prelude(string $source, string $css, int $from, int $to): array
    {
        $quote = null;
        $parens = 0;
        for ($i = $from; $i < $to; $i++) {
            $char = $css[$i];
            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(') {
                $parens++;
            } elseif ($char === ')') {
                $parens--;
            } elseif ($parens <= 0 && in_array($char, ['{', ';', '}'], true)) {
                return [substr($css, $from, $i - $from), $i, $char];
            }
        }
        if ($quote !== null || $parens > 0) {
            throw new RuntimeException('unterminated string or parenthesis at line '.self::line($source, $from));
        }

        return [substr($css, $from, $to - $from), $to, null];
    }

    /**
     * Skips a statement-only directive from just after its name: it ends at `;`, at the block's end, or at a line break (parentheses closed)
     * whose next line does not continue it with a `modifier(...)`. A `@plugin` may carry an options block, read as opaque.
     */
    private static function statement(string $source, string $css, int $from, int $to, bool $options): int
    {
        $quote = null;
        $parens = 0;
        for ($i = $from; $i < $to; $i++) {
            $char = $css[$i];
            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(') {
                $parens++;
            } elseif ($char === ')') {
                $parens--;
            } elseif ($parens <= 0 && $char === ';') {
                return $i + 1;
            } elseif ($parens <= 0 && $options && $char === '{') {
                return self::matching($source, $css, $i, $to) + 1;
            } elseif ($parens <= 0 && $char === "\n" && preg_match('~\G\s*[a-z][\w-]*\(~i', $css, $continued, 0, $i + 1) !== 1) {
                return $i + 1;
            }
        }
        if ($quote !== null || $parens > 0) {
            throw new RuntimeException('unterminated string or parenthesis at line '.self::line($source, $from));
        }

        return $to;
    }

    private static function matching(string $source, string $css, int $open, int $to): int
    {
        $depth = 0;
        $quote = null;
        for ($i = $open; $i < $to; $i++) {
            $char = $css[$i];
            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}' && --$depth === 0) {
                return $i;
            }
        }

        throw new RuntimeException('unclosed { at line '.self::line($source, $open));
    }

    private static function tokenSelector(string $selector): bool
    {
        foreach (explode(',', $selector) as $part) {
            if (! in_array(trim($part), [':root', '.dark', ':root.dark', 'html.dark'], true)) {
                return false;
            }
        }

        return true;
    }

    private static function hasClass(string $selector): bool
    {
        $selector = (string) preg_replace(['~\[[^\]]*\]~', '~"[^"]*"|\'[^\']*\'~'], '', $selector);

        return preg_match('~\.(?!dark\b)-?[_a-zA-Z\\\\]~', $selector) === 1;
    }

    /**
     * Replaces each match with spaces (newlines kept, so offsets and lines hold). A match that runs to the end without its closer fails.
     */
    private static function blank(string $source, string $pattern, string $opener, string $closer, bool $insensitive = false): string
    {
        return (string) preg_replace_callback($pattern, function (array $match) use ($opener, $closer, $insensitive): string {
            $text = $match[0];
            // A pattern may match strings first so an opener inside one (`'app/**/*.vue'`) is left alone.
            if (! str_starts_with($text, $opener)) {
                return $text;
            }
            $tail = $insensitive ? stripos($text, $closer, strlen($opener)) : strpos($text, $closer, strlen($opener));
            if ($tail === false) {
                throw new RuntimeException('unterminated '.$opener);
            }

            return (string) preg_replace('~[^\n]~', ' ', $text);
        }, $source);
    }

    /** @return array{check: string, line: int, hint: string} */
    private static function unparsed(string $source, RuntimeException $error): array
    {
        preg_match('~line (\d+)~', $error->getMessage(), $line);

        return ['check' => 'could not parse the file', 'line' => isset($line[1]) ? (int) $line[1] : 1, 'hint' => 'The rule fails closed ('.$error->getMessage().'): fix the markup so it can be checked.'];
    }

    private static function line(string $source, int $offset): int
    {
        return substr_count($source, "\n", 0, min($offset, strlen($source))) + 1;
    }
}
