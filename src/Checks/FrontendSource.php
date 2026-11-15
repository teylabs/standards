<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use Tey\Standards\Configuration;
use Tey\Standards\Source;

final class FrontendSource
{
    public function __construct(private readonly Configuration $config) {}

    /** @return array<string, string> */
    public function files(): array
    {
        $roots = $this->config->frontendRoots;

        if ($roots === null) {
            $roots = array_values(array_filter(['resources/js', ...$this->config->uiRoots], fn (string $root): bool => (glob($this->config->base.'/'.$root, GLOB_ONLYDIR) ?: []) !== []));
        }

        return (new Source($this->config))->files($roots, Source::FRONTEND);
    }

    /**
     * The files the app owns: files() without the ones tey/kit owns (KitOwnership), for the rules that judge the app's own UI code.
     *
     * @return array<string, string>
     */
    public function appFiles(): array
    {
        $kit = KitOwnership::of($this->config);

        return array_filter($this->files(), fn (string $source, string $path): bool => ! $kit->owns($path, $source), ARRAY_FILTER_USE_BOTH);
    }

    public static function script(string $path, string $source): string
    {
        if (! str_ends_with($path, '.vue')) {
            return $source;
        }

        $source = preg_replace('~<!--.*?-->~s', '', $source) ?? '';
        preg_match_all('~<script\b[^>]*>(.*?)</script\s*>~si', $source, $matches);

        return implode("\n", $matches[1]);
    }

    /**
     * Small lexical scan: preserve quoted specifiers, discard comments, and
     * treat template strings and regex literals as opaque. It does not evaluate JavaScript.
     *
     * A `/` starts a regex literal where an operand is expected (at the start, after an operator or punctuator other than `)` and `]`, or after a
     * keyword such as `return`); after an identifier, a number, a string, `)` or `]` it is division.
     *
     * @return list<string>
     */
    public static function tokens(string $source): array
    {
        $tokens = [];
        $offset = 0;
        while (preg_match('~//[^\r\n]*|/\*.*?\*/|"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|`(?:\\\\.|[^`\\\\])*`|[\w$]+|\?\.|[^\s]~s', $source, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            [$token, $at] = $match[0];
            if ($token === '/' && self::operandExpected($tokens === [] ? null : $tokens[array_key_last($tokens)])
                && preg_match('~\G/(?:\\\\[^\r\n]|\[(?:\\\\[^\r\n]|[^\]\\\\\r\n])*\]|[^/\\\\\[\r\n])+/[A-Za-z]*~', $source, $regex, 0, $at) === 1) {
                $token = $regex[0];
            }
            $offset = $at + strlen($token);
            if (! str_starts_with($token, '//') && ! str_starts_with($token, '/*')) {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    private static function operandExpected(?string $previous): bool
    {
        if ($previous === null) {
            return true;
        }
        if (preg_match('~^[\w$]+$~', $previous) === 1) {
            return in_array($previous, ['return', 'typeof', 'case', 'do', 'else', 'in', 'of', 'instanceof', 'new', 'void', 'delete', 'throw', 'yield', 'await'], true);
        }

        // Strings, templates and regex literals are longer than one character; every other one-character token is a punctuator.
        return strlen($previous) === 1 && ! in_array($previous, [')', ']'], true);
    }

    /** @return list<string> */
    public static function imports(string $source): array
    {
        $tokens = self::tokens($source);
        $imports = [];

        foreach ($tokens as $index => $token) {
            if (! in_array($token, ['import', 'export'], true) || in_array($tokens[$index - 1] ?? '', ['.', '?.'], true)) {
                continue;
            }

            $next = $tokens[$index + 1] ?? '';
            if ($token === 'import' && $next === '(') {
                $next = $tokens[$index + 2] ?? '';
            } elseif (! self::quoted($next)) {
                for ($cursor = $index + 1; $cursor < count($tokens); $cursor++) {
                    if (in_array($tokens[$cursor], [';', 'import', 'export', '='], true)) {
                        break;
                    }
                    if ($tokens[$cursor] === 'from' && self::quoted($tokens[$cursor + 1] ?? '')) {
                        $next = $tokens[$cursor + 1] ?? '';
                        break;
                    }
                }
            }

            if (self::quoted($next)) {
                $imports[] = substr($next, 1, -1);
            }
        }

        return array_values(array_unique($imports));
    }

    private static function quoted(string $value): bool
    {
        return str_starts_with($value, "'") || str_starts_with($value, '"') || (str_starts_with($value, '`') && ! str_contains($value, '${'));
    }

    /** @return list<array{tag: string, attributes: string, inner: string}> */
    public static function actions(string $source, Configuration $config): array
    {
        $source = self::template($source);
        $names = implode('|', array_map(fn (string $name): string => preg_quote($name, '~'), $config->uiActionComponents));
        preg_match_all('~<('.$names.')\b((?:"[^"]*"|\'[^\']*\'|[^\'">])*)>~s', $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $actions = [];
        foreach ($matches as $match) {
            $inner = '';
            if (! str_ends_with(rtrim($match[2][0]), '/')) {
                $start = $match[0][1] + strlen($match[0][0]);
                $end = strpos($source, '</'.$match[1][0], $start);
                if ($end !== false) {
                    $inner = substr($source, $start, $end - $start);
                }
            }
            $actions[] = ['tag' => $match[1][0], 'attributes' => $match[2][0], 'inner' => $inner];
        }

        return $actions;
    }

    public static function template(string $source): string
    {
        return preg_replace(['~<!--.*?-->~s', '~<(script|style)\b[^>]*>.*?</\1\s*>~si'], '', $source) ?? '';
    }

    public static function attribute(string $attributes, string $name): ?string
    {
        return preg_match('~(?:^|\s)'.preg_quote($name, '~').'\s*=\s*([\'"])(.*?)\1~s', $attributes, $match) ? $match[2] : null;
    }

    /** @param list<string> $icons */
    public static function hasIcon(string $source, array $icons): bool
    {
        foreach ($icons as $icon) {
            if (preg_match('~<'.preg_quote($icon, '~').'(?=[\s/>])|\bicon\s*=\s*[\'"]'.preg_quote($icon, '~').'[\'"]~', $source)) {
                return true;
            }
        }

        return false;
    }
}
