<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use Tey\Standards\Configuration;
use Tey\Standards\Source;

/**
 * Pure Inertia: an app's own UI gets its data as props and makes its changes as visits (partial reloads, `Inertia::defer()` / `optional()`, `usePoll`,
 * `Inertia::scroll()`, `useForm` / `<Form>`, server-driven modals). Flagged in the app's PHP: `response()->json(`, `new JsonResponse` / a `JsonResponse`
 * return type, `expectsJson()`, `wantsJson()`, `->ajax()` and `Negotiated`. Flagged in its frontend: `fetch(`, `axios`, `XMLHttpRequest`, `useHttp` and
 * `sendJson`. `useHttp` is not an exemption.
 *
 * The allowed surfaces are exempt by a one-line marker on the line or the line above: `// inertia.pure: <category> <reason>`, where the category is one of
 * CATEGORIES (an unknown category, or a marker without a reason, is itself reported). `*WebhookController` classes are exempt by name, and files tey/kit owns
 * are not scanned. Advisory: a grep cannot tell a page route from a public API, so existing hits are baselined with `standards:allowlist --baseline
 * --rule=inertia.pure --expires=<date>` and the rule stays advisory until false positives are measured.
 */
final class InertiaPure
{
    /** The allowed non-Inertia surfaces (house rule, 2026-11-06). Anything else needs an explicit yes. */
    public const CATEGORIES = ['webhook', 'third-party', 'mcp', 'public-api', 'binary', 'signed-link', 'upload-signing', 'stream', 'package', 'typeahead'];

    private const PHP = [
        'response()->json(' => '~\bresponse\(\)\s*->\s*json\s*\(~',
        'JsonResponse' => '~\bnew\s+(?:\\\\?Illuminate\\\\Http\\\\)?JsonResponse\b|\)\s*:\s*\??(?:\\\\?Illuminate\\\\Http\\\\)?JsonResponse\b~',
        'expectsJson()' => '~->\s*expectsJson\s*\(~',
        'wantsJson()' => '~->\s*wantsJson\s*\(~',
        'ajax()' => '~->\s*ajax\s*\(~',
        'Negotiated' => '~\bNegotiated\b~',
    ];

    private const JS = [
        'fetch(' => '~(?<![\w.$])fetch\s*\(~',
        'axios' => '~\baxios\b~',
        'XMLHttpRequest' => '~\bXMLHttpRequest\b~',
        'useHttp' => '~\buseHttp\b~',
        'sendJson' => '~\bsendJson\b~',
    ];

    /** @return list<array{target: string, check: string}> */
    public function violations(Configuration $config, Source $source): array
    {
        $violations = [];
        $roots = array_values(array_unique(array_keys($config->classRoots)));
        foreach ($source->files($roots) as $path => $text) {
            if (str_ends_with($path, 'WebhookController.php')) {
                continue;
            }
            array_push($violations, ...self::scan($path, $text, self::PHP, 'php'));
        }
        foreach ((new FrontendSource($config))->appFiles() as $path => $text) {
            if (preg_match('~\.(?:test|spec)\.[cm]?[jt]s$~', $path) === 1) {
                continue;
            }
            array_push($violations, ...self::scan($path, $text, self::JS, 'js'));
        }

        return array_values(array_unique($violations, SORT_REGULAR));
    }

    /**
     * @param  array<string, string>  $patterns
     * @return list<array{target: string, check: string}>
     */
    private static function scan(string $path, string $text, array $patterns, string $language): array
    {
        $found = [];
        $lines = preg_split('~\R~', $text) ?: [];
        foreach ($lines as $index => $line) {
            $code = self::code($line, $language);
            foreach ($patterns as $label => $pattern) {
                if (preg_match($pattern, $code) !== 1) {
                    continue;
                }
                $marker = self::marker($line) ?? ($index > 0 ? self::marker($lines[$index - 1]) : null);
                if ($marker === null) {
                    $found[] = ['target' => $path, 'check' => "{$label} in your own UI: use props and visits (partial reload, defer/optional, usePoll, useForm), or mark an allowed surface `// inertia.pure: <category> <reason>`"];
                } elseif (! in_array($marker['category'], self::CATEGORIES, true)) {
                    $found[] = ['target' => $path, 'check' => "{$label}: `inertia.pure: {$marker['category']}` is not an allowed category (".implode(', ', self::CATEGORIES).')'];
                } elseif ($marker['reason'] === '') {
                    $found[] = ['target' => $path, 'check' => "{$label}: `inertia.pure: {$marker['category']}` needs a one-line reason"];
                }
            }
        }

        return $found;
    }

    /** @return array{category: string, reason: string}|null */
    private static function marker(string $line): ?array
    {
        if (preg_match('~(?://|#|/\*|\*|<!--)\s*inertia\.pure:\s*([\w-]+)\s*(.*?)\s*(?:\*/|-->)?\s*$~', $line, $match) !== 1) {
            return null;
        }

        return ['category' => $match[1], 'reason' => trim($match[2], " \t—-:")];
    }

    /** The line without a trailing `//` comment or a whole-line comment, so prose that names `fetch(` is not a hit. */
    private static function code(string $line, string $language): string
    {
        $trimmed = ltrim($line);
        // A PHP `use` import names a class without using it: the use is what is judged.
        if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*') || ($language === 'php' && (str_starts_with($trimmed, '#') || preg_match('~^use\s+[\w\\\\]+(?:\s+as\s+\w+)?\s*;~', $trimmed) === 1))) {
            return '';
        }

        return preg_replace('~\s//.*$~', '', $line) ?? $line;
    }
}
