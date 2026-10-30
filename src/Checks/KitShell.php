<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use Tey\Standards\Ignored;
use Symfony\Component\Finder\Finder;
use Tey\Standards\Configuration;
use Tey\Standards\Source;

/**
 * Rule `ui.kit-shell`: an app that adopted tey/kit's `shell` feature renders its signed-in pages in kit's Workspace Layout, not its own header.
 *
 * Active only when the shell is adopted: `config/kit.php` sets `features` to a literal list naming `shell` (or `session-ui`, which carries
 * it), or, when that key is absent or null, an installed tey/mod lineage record names `tey/kit:shell` (or `tey/kit:session-ui`). A literal
 * list without either means not adopted, whatever the records say, as in kit. Otherwise the rule reports nothing.
 *
 * - Layout resolution: in `app.ts`/`app.js` under a frontend root, the `layout:` option of `createInertiaApp(...)` and any `.layout =`,
 *   `??=` or `||=` assignment (a persistent layout default in `resolve`). Each identifier there that is a default or named import of an
 *   app-owned `.vue` file is a finding unless that file reaches kit's Layout (it, or an app `.vue` it imports, up to three levels, imports
 *   `pages/Workspace/Layout.vue` or `Layout` from `@kit/shell`), or its path names a public, guest or focus surface. In an array layout only
 *   the first element is the shell; the rest are page sublayouts. A value it cannot read (an identifier it cannot trace to an import or a local
 *   function, a `.ts` module, a glob lookup) is not flagged.
 * - Headers: an app-owned `.vue` file with a `<header>` or `<nav>` element whose markup holds a sign-out (`logout`, `log out`, `sign out`),
 *   an account or user menu component, or a workspace switcher component. Not checked: kit-owned files (KitOwnership), the shell feature's own
 *   paths when no record lists them (ownership unknown), page components (`Page.vue`), files whose path names a public, guest or focus surface,
 *   and files that import kit's Layout themselves (the content they pass to its slots is sanctioned).
 *
 * Static and conservative: it prefers missing a case to flagging one it cannot prove. An entry file or `.vue` file it cannot read is a finding (fails closed).
 */
final class KitShell
{
    public const RULE = 'ui.kit-shell';

    private const KIT_LAYOUT = '/pages/Workspace/Layout.vue';

    private const SHELL_PARTIALS = '/pages/Workspace/Partials/';

    private const FEATURES = ['shell', 'session-ui'];

    private const LINEAGES = ['tey/kit:shell', 'tey/kit:session-ui'];

    /** A path segment that names a public, guest or focus surface: `PublicHeader`, `GuestLayout`, `auth/`, `Marketing`. `Authenticated` does not match. */
    private const EXEMPT = '~^(?:[Aa]uth|[Ll]ogin|[Gg]uest|[Pp]ublic|[Mm]arketing|[Ll]anding|[Ww]elcome|[Ff]ocus|[Dd]ocs|[Ll]egal|[Hh]elp|[Ee]rror|[Pp]rint)(?![a-z])~';

    private const SIGNALS = [
        'a sign-out link' => '~\b(?:log|sign)[\s_-]?out\b~i',
        'an account menu' => '~<(?:[A-Z]\w*)?(?:User|Account|Profile)(?:Menu\w*|(?:Dropdown|Nav|Navigation)\b)|<NavUser\b|<(?:user|account|profile)-(?:menu|dropdown|nav)\b~',
        'a workspace switcher' => '~<(?:[A-Z]\w*)?(?:Workspace|Team|Tenant|Organization|Organisation|Org)(?:Switcher|Picker|Selector)\b|<(?:workspace|team|tenant|org|organization)-(?:switcher|picker|selector)\b~',
    ];

    private const HINT = 'Render signed-in pages in kit\'s Workspace Layout (resources/js/pages/Workspace/Layout.vue): return it (or an app wrapper that renders it) from app.ts\'s layout callback, and put app parts in its slots (`mark`, `nav`, `drawer-nav`, `bar`, `actions`, `extra`, `user-menu`, `workspace-switcher`, `footer`) and its pages in the server\'s navigation (Registry::navigation). Public, guest and focus pages keep PublicShell, GuestShell or their own layout.';

    private const UNREADABLE = 'The rule fails closed: make the file readable so it can be checked.';

    /** @return list<array{target: string, check: string, line?: int, hint?: string}> */
    public function violations(Configuration $config): array
    {
        if (! self::adopted($config->base)) {
            return [];
        }
        $kit = KitOwnership::of($config);
        $roots = array_values(array_unique($config->frontendRoots ?? ['resources/js', ...$config->uiRoots]));

        return [...$this->resolvers($config->base, $roots, $kit), ...$this->headers($config->base, $roots, $kit)];
    }

    /**
     * Whether the app adopted kit's shell (or, given other lists, another kit feature): a literal `kit.features` list decides; else the installed lineage records.
     *
     * @param  list<string>  $features  `kit.features` ids, any of which adopts it
     * @param  list<string>  $lineages  tey/mod lineage names, any of which adopts it
     */
    public static function adopted(string $base, array $features = self::FEATURES, array $lineages = self::LINEAGES): bool
    {
        $file = $base.'/config/kit.php';
        if (is_file($file) && ! is_link($file) && is_readable($file)) {
            $source = self::phpWithoutComments((string) file_get_contents($file));
            if (preg_match("~(['\"])features\\1\\s*=>\\s*\\[([^\\]]*)\\]~", $source, $list)) {
                preg_match_all("~(['\"])([a-z0-9-]+)\\1~", $list[2], $ids);

                return array_intersect($features, $ids[2]) !== [];
            }
        }
        $records = glob($base.'/'.KitOwnership::RECORDS.'/*.json') ?: [];
        if (is_file($base.'/'.KitOwnership::LEGACY_RECORD)) {
            $records[] = $base.'/'.KitOwnership::LEGACY_RECORD;
        }
        foreach ($records as $record) {
            if (is_link($record) || ! preg_match('/\A(?:[a-f0-9]{64}|installed)\.json\z/', basename($record))) {
                continue;
            }
            $data = json_decode((string) @file_get_contents($record), true);
            if (is_array($data) && ($data['format'] ?? null) === 1 && ($data['owner'] ?? null) === 'tey/mod:installed'
                && in_array($data['artifact']['name'] ?? null, $lineages, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $roots
     * @return list<array{target: string, check: string, line: int, hint: string}>
     */
    private function resolvers(string $base, array $roots, KitOwnership $kit): array
    {
        $findings = [];
        foreach ($roots as $root) {
            foreach (['app.ts', 'app.js'] as $name) {
                $entry = trim($root, '/').'/'.$name;
                $file = $base.'/'.$entry;
                if (! is_file($file) || is_link($file)) {
                    continue;
                }
                $source = is_readable($file) ? @file_get_contents($file) : false;
                if ($source === false) {
                    $findings[] = ['target' => $entry, 'check' => 'could not read the file', 'line' => 1, 'hint' => self::UNREADABLE];

                    continue;
                }
                $code = VueImports::code($source);
                $imports = VueImports::imports($code);
                $seen = [];
                foreach (self::layoutValues($code) as [$value, $offset]) {
                    foreach (self::layoutIdentifiers($value, $offset, $code, $imports) as [$identifier, $at]) {
                        [$kind, $path] = $this->kind($base, $imports[$identifier], $entry, $kit);
                        if ($kind !== 'app' || isset($seen[$path]) || self::exempt($path) || $this->reachesKit($base, $path, $kit)) {
                            continue;
                        }
                        $seen[$path] = true;
                        $findings[] = [
                            'target' => $entry,
                            'check' => 'signed-in pages resolve to an app-owned layout ('.$path.'), not kit\'s Workspace Layout',
                            'line' => self::line($source, $at),
                            'hint' => self::HINT,
                        ];
                    }
                }
            }
        }

        return $findings;
    }

    /**
     * @param  list<string>  $roots
     * @return list<array{target: string, check: string, line: int, hint: string}>
     */
    private function headers(string $base, array $roots, KitOwnership $kit): array
    {
        $files = [];
        foreach ($roots as $root) {
            foreach (glob($base.'/'.$root, GLOB_ONLYDIR) ?: [] as $directory) {
                foreach ((new Finder)->files()->in($directory)->name('*.vue')->exclude(['vendor', 'node_modules'])->ignoreDotFiles(false) as $file) {
                    $path = str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1));
                    if (! str_starts_with($path, Source::GENERATED)) {
                        $contents = is_readable($file->getPathname()) ? @file_get_contents($file->getPathname()) : false;
                        $files[$path] = $contents === false ? null : $contents;
                    }
                }
            }
        }
        $files = Ignored::filterKeys($base, $files);
        ksort($files);
        $findings = [];
        foreach ($files as $path => $source) {
            if ($source === null) {
                $findings[] = ['target' => $path, 'check' => 'could not read the file', 'line' => 1, 'hint' => self::UNREADABLE];

                continue;
            }
            if ($kit->owns($path, $source) || (self::shellPath($path) && ! $kit->recorded($path)) || basename($path) === 'Page.vue' || self::exempt($path)) {
                continue;
            }
            $imports = VueImports::imports(VueImports::code(VueImports::scripts($source)));
            foreach ($imports as $import) {
                if ($this->kind($base, $import, $path, $kit)[0] === 'kit-layout') {
                    continue 2;
                }
            }
            foreach (self::header($source) as $finding) {
                $findings[] = ['target' => $path, ...$finding];
            }
        }

        return $findings;
    }

    /**
     * The signed-in header or nav roots in one `.vue` file: a `<header>` or `<nav>` whose markup holds a sign-out, an account menu or a workspace switcher.
     *
     * @return list<array{check: string, line: int, hint: string}>
     */
    public static function header(string $source): array
    {
        $template = self::blank($source, '~<!--.*?(?:-->|\z)~s');
        $template = self::blank($template, '~<(script|style)\b[^>]*>.*?(?:</\1\s*>|\z)~si');
        $findings = [];
        $covered = -1;
        preg_match_all('~<(header|nav)(?=[\s>/])~', $template, $tags, PREG_OFFSET_CAPTURE);
        foreach ($tags[1] as [$tag, $offset]) {
            if ($offset <= $covered) {
                continue;
            }
            $end = self::closing($template, $tag, $offset);
            $markup = substr($template, $offset, $end - $offset);
            $signals = array_keys(array_filter(self::SIGNALS, fn (string $pattern): bool => preg_match($pattern, $markup) === 1));
            if ($signals === []) {
                continue;
            }
            $covered = $end;
            $findings[] = [
                'check' => 'signed-in <'.$tag.'> outside kit\'s shell',
                'line' => self::line($source, $offset),
                'hint' => 'This <'.$tag.'> holds '.implode(', ', $signals).', so it is a signed-in app header kit\'s shell already renders. '.self::HINT,
            ];
        }

        return $findings;
    }

    /** The offset just past the element's matching close tag, counting nested elements of the same name; the end of the source when it never closes. */
    private static function closing(string $template, string $tag, int $offset): int
    {
        preg_match_all('~<(/?)'.$tag.'(?=[\s>/])[^>]*>~', $template, $marks, PREG_SET_ORDER | PREG_OFFSET_CAPTURE, $offset);
        $depth = 0;
        foreach ($marks as $mark) {
            $selfClosing = str_ends_with($mark[0][0], '/>');
            if ($mark[1][0] === '/') {
                $depth--;
            } elseif (! $selfClosing) {
                $depth++;
            }
            if ($depth <= 0) {
                return $mark[0][1] + strlen($mark[0][0]);
            }
        }

        return strlen($template);
    }

    /**
     * What an imported name is: kit's Workspace Layout, another kit part, an app-owned `.vue` file, or something this rule does not follow.
     *
     * @param  array{0: string, 1: string}  $import  specifier and imported name
     * @return array{0: 'kit-layout'|'kit'|'app'|'other', 1: string}
     */
    private function kind(string $base, array $import, string $from, KitOwnership $kit): array
    {
        [$specifier, $imported] = $import;
        if ($specifier === '@kit/shell') {
            return [$imported === 'Layout' ? 'kit-layout' : 'kit', ''];
        }
        $path = VueImports::resolve($base, $specifier, $from);
        if ($path === null) {
            return ['other', ''];
        }
        if (str_ends_with('/'.$path, self::KIT_LAYOUT)) {
            return ['kit-layout', $path];
        }
        if (str_contains('/'.$path, self::SHELL_PARTIALS) || str_starts_with($path, KitOwnership::KIT_TREE)) {
            return ['kit', $path];
        }
        $file = $base.'/'.$path;
        if (! str_ends_with($path, '.vue') || ! is_file($file) || ! is_readable($file)) {
            return ['other', ''];
        }

        return $kit->owns($path, (string) file_get_contents($file)) ? ['kit', $path] : ['app', $path];
    }

    /** Whether an app `.vue` file, or an app `.vue` file it imports (three levels at most), imports kit's Workspace Layout. */
    private function reachesKit(string $base, string $path, KitOwnership $kit): bool
    {
        return VueImports::reaches(
            $base,
            $path,
            3,
            fn (string $file, string $source, array $imports): bool => array_any($imports, fn (array $import): bool => $this->kind($base, $import, $file, $kit)[0] === 'kit-layout'),
            function (array $import, string $from) use ($base, $kit): ?string {
                [$kind, $next] = $this->kind($base, $import, $from, $kit);

                return $kind === 'app' ? $next : null;
            },
        );
    }

    private static function shellPath(string $path): bool
    {
        return str_ends_with('/'.$path, self::KIT_LAYOUT) || str_contains('/'.$path, self::SHELL_PARTIALS);
    }

    private static function exempt(string $path): bool
    {
        foreach (explode('/', (string) preg_replace('~\.vue$~', '', $path)) as $segment) {
            if (preg_match(self::EXEMPT, $segment) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Each layout expression in an entry file, with its offset: `layout:` in `createInertiaApp`'s options, and `.layout =`, `??=` or `||=` assignments.
     *
     * @return list<array{0: string, 1: int}>
     */
    private static function layoutValues(string $code): array
    {
        $values = [];
        preg_match_all('~\bcreateInertiaApp\s*\(~', $code, $calls, PREG_OFFSET_CAPTURE);
        foreach ($calls[0] as [$call, $offset]) {
            $open = $offset + strlen($call) - 1;
            $close = self::matching($code, $open);
            preg_match_all('~\blayout\s*:~', substr($code, $open, $close - $open), $keys, PREG_OFFSET_CAPTURE);
            foreach ($keys[0] as [$key, $at]) {
                $start = $open + $at + strlen($key);
                if (self::depth($code, $open, $open + $at) === 2) {
                    $values[] = [self::expression($code, $start, false), $start];
                }
            }
        }
        preg_match_all('~\.layout\s*(?:\?\?=|\|\|=|=(?![=>]))~', $code, $assignments, PREG_OFFSET_CAPTURE);
        foreach ($assignments[0] as [$assignment, $at]) {
            $start = $at + strlen($assignment);
            $values[] = [self::expression($code, $start, true), $start];
        }

        return $values;
    }

    /**
     * The imported names a layout expression returns, with their offsets in the file. An identifier that names a local function or constant
     * is followed into it once. Strings and every array element but the first are not read.
     *
     * @param  array<string, array{0: string, 1: string}>  $imports
     * @return list<array{0: string, 1: int}>
     */
    private static function layoutIdentifiers(string $value, int $offset, string $code, array $imports, bool $follow = true): array
    {
        $trimmed = trim($value);
        if ($follow && preg_match('~^[A-Za-z_$][\w$]*$~', $trimmed) && ! isset($imports[$trimmed])) {
            if (preg_match('~\bfunction\s+'.preg_quote($trimmed, '~').'\s*\([^)]*\)\s*(?::[^{]*)?\{~', $code, $function, PREG_OFFSET_CAPTURE)) {
                $open = $function[0][1] + strlen($function[0][0]) - 1;

                return self::layoutIdentifiers(substr($code, $open, self::matching($code, $open) - $open), $open, $code, $imports, false);
            }
            if (preg_match('~\b(?:const|let|var)\s+'.preg_quote($trimmed, '~').'\s*(?::[^=]*)?=(?![=>])~', $code, $local, PREG_OFFSET_CAPTURE)) {
                $start = $local[0][1] + strlen($local[0][0]);

                return self::layoutIdentifiers(self::expression($code, $start, true), $start, $code, $imports, false);
            }

            return [];
        }
        $text = self::firstElements(VueImports::code($value, strings: true));
        preg_match_all('~(?<![\w$.])[A-Za-z_$][\w$]*(?![\w$])~', $text, $names, PREG_OFFSET_CAPTURE);
        $found = [];
        foreach ($names[0] as [$name, $at]) {
            if (isset($imports[$name])) {
                $found[] = [$name, $offset + $at];
            }
        }

        return $found;
    }

    /** Blanks every array element after the first, so `[Shell, sublayout]` reads as `Shell`. Index access (`map[key]`) has one element and is unchanged. */
    private static function firstElements(string $text): string
    {
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            if ($text[$i] !== '[') {
                continue;
            }
            $close = self::matching($text, $i);
            $depth = 0;
            for ($j = $i + 1; $j < $close; $j++) {
                $char = $text[$j];
                if (in_array($char, ['(', '[', '{'], true)) {
                    $depth++;
                } elseif (in_array($char, [')', ']', '}'], true)) {
                    $depth--;
                } elseif ($char === ',' && $depth === 0) {
                    $text = substr($text, 0, $j).(string) preg_replace('~[^\n]~', ' ', substr($text, $j, $close - $j)).substr($text, $close);

                    break;
                }
            }
        }

        return $text;
    }

    /**
     * The expression starting at an offset: up to a `,` or closing bracket at its own depth, or, for a statement, a `;` or a line end that does
     * not continue the expression.
     */
    private static function expression(string $code, int $start, bool $statement): string
    {
        $depth = 0;
        $quote = null;
        $length = strlen($code);
        for ($i = $start; $i < $length; $i++) {
            $char = $code[$i];
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
                if (--$depth < 0) {
                    break;
                }
            } elseif ($depth === 0 && ($char === ',' || ($statement && $char === ';'))) {
                break;
            } elseif ($depth === 0 && $statement && $char === "\n") {
                $before = rtrim(substr($code, $start, $i - $start));
                $after = ltrim(substr($code, $i, 200));
                if ($before !== '' && ! preg_match('~[?:|&=,(+\-*/<>!]$~', $before) && ! preg_match('~^[?:|&.+\-*/]~', $after)) {
                    break;
                }
            }
        }

        return substr($code, $start, $i - $start);
    }

    /** The bracket depth at an offset, counted from an opening bracket (depth 1 just inside it). */
    private static function depth(string $code, int $from, int $to): int
    {
        $depth = 0;
        $quote = null;
        for ($i = $from; $i < $to; $i++) {
            $char = $code[$i];
            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }
            } elseif (in_array($char, ['"', "'", '`'], true)) {
                $quote = $char;
            } elseif (in_array($char, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($char, [')', ']', '}'], true)) {
                $depth--;
            }
        }

        return $depth;
    }

    /** The offset of the bracket that closes the one at `$open`; the end of the text when it never closes. */
    private static function matching(string $code, int $open): int
    {
        $depth = 0;
        $quote = null;
        $length = strlen($code);
        for ($i = $open; $i < $length; $i++) {
            $char = $code[$i];
            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }
            } elseif (in_array($char, ['"', "'", '`'], true)) {
                $quote = $char;
            } elseif (in_array($char, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($char, [')', ']', '}'], true) && --$depth === 0) {
                return $i;
            }
        }

        return $length;
    }

    private static function phpWithoutComments(string $source): string
    {
        $out = '';
        foreach (token_get_all($source) as $token) {
            $out .= is_array($token) ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1]) : $token;
        }

        return $out;
    }

    private static function blank(string $source, string $pattern): string
    {
        return (string) preg_replace_callback($pattern, fn (array $match): string => (string) preg_replace('~[^\n]~', ' ', $match[0]), $source);
    }

    private static function line(string $source, int $offset): int
    {
        return substr_count($source, "\n", 0, min($offset, strlen($source))) + 1;
    }
}
