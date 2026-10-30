<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use InvalidArgumentException;
use Tey\Standards\Configuration;

final class JsImports
{
    /** @return list<array{target: string, check: string}> */
    public function violations(Configuration $config): array
    {
        $files = (new FrontendSource($config))->files();
        if ($files === []) {
            return [];
        }

        $manifest = $config->base.'/package.json';
        if (! is_file($manifest)) {
            return [['target' => 'package.json', 'check' => 'missing package manifest for frontend imports']];
        }

        $package = json_decode((string) file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($package)) {
            throw new InvalidArgumentException('package.json must contain an object.');
        }

        $declared = array_keys(array_merge($package['dependencies'] ?? [], $package['devDependencies'] ?? [], $package['peerDependencies'] ?? []));
        $viteAliases = self::viteAliases($config->base, ['vite.config.ts', 'vite.config.js']);
        $violations = [];
        foreach ($files as $path => $source) {
            foreach (FrontendSource::imports(FrontendSource::script($path, $source)) as $specifier) {
                if (str_starts_with($specifier, '.') || str_starts_with($specifier, 'node:') || array_any($config->frontendAliases, fn (string $alias): bool => str_starts_with($specifier, $alias)) || array_any($viteAliases, fn (string $alias): bool => $specifier === $alias || str_starts_with($specifier, rtrim($alias, '/').'/'))) {
                    continue;
                }

                $name = implode('/', array_slice(explode('/', $specifier), 0, str_starts_with($specifier, '@') ? 2 : 1));
                if (! in_array($name, $declared, true)) {
                    $violation = ['target' => $path, 'check' => 'undeclared package import: '.$specifier];
                    $hint = self::transitiveHint($config, $name, $package);

                    $violations[] = $hint === null ? $violation : [...$violation, 'hint' => $hint];
                }
            }
        }

        return $violations;
    }

    /**
     * A package another declared one already depends on (`@inertiajs/core` under `@inertiajs/vue3`): declaring it at a looser range (`^3`)
     * can install a second copy, and two copies of the same types break vue-tsc. So the hint names the range the parent asks for, read from
     * node_modules when it is installed. Null for an ordinary undeclared package.
     *
     * @param  array<string, mixed>  $package
     */
    private static function transitiveHint(Configuration $config, string $name, array $package): ?string
    {
        foreach (array_keys(array_merge($package['dependencies'] ?? [], $package['devDependencies'] ?? [])) as $parent) {
            $manifest = $config->base.'/node_modules/'.$parent.'/package.json';
            $parentPackage = is_file($manifest) ? json_decode((string) file_get_contents($manifest), true) : null;
            $range = is_array($parentPackage) ? ($parentPackage['dependencies'][$name] ?? $parentPackage['peerDependencies'][$name] ?? null) : null;

            if (is_string($range)) {
                return "{$name} comes with {$parent}, which depends on {$name}@{$range}. Declare it at that same range (\"{$name}\": \"{$range}\"), or pin it for every package with an npm `overrides` entry, so npm installs one copy: a looser range can install a second, and two copies of the same types break vue-tsc. Check with `npm ls {$name}` (one version, no duplicates).";
            }
        }

        return null;
    }

    /**
     * Static keys of `resolve.alias` in vite.config.ts/js: object keys, or `find`
     * strings of an array literal. Computed keys, spreads, regular expressions
     * and aliases held in variables are ignored.
     *
     * The first of `$files` that exists is read.
     *
     * @param  list<string>  $files
     * @return list<string>
     */
    public static function viteAliases(string $base, array $files = ['vite.config.ts', 'vite.config.js', 'vite.config.mts', 'vite.config.mjs']): array
    {
        foreach ($files as $file) {
            if (! is_file($base.'/'.$file)) {
                continue;
            }
            $tokens = FrontendSource::tokens((string) file_get_contents($base.'/'.$file));
            $aliases = [];
            foreach ($tokens as $index => $token) {
                if ($token !== 'alias' || ($tokens[$index + 1] ?? '') !== ':' || ! in_array($tokens[$index + 2] ?? '', ['{', '['], true)) {
                    continue;
                }
                $isObject = $tokens[$index + 2] === '{';
                $depth = 0;
                for ($cursor = $index + 2; $cursor < count($tokens); $cursor++) {
                    $current = $tokens[$cursor];
                    if (in_array($current, ['{', '[', '('], true)) {
                        $depth++;
                    } elseif (in_array($current, ['}', ']', ')'], true)) {
                        if (--$depth === 0) {
                            break;
                        }
                    } elseif (($tokens[$cursor + 1] ?? '') === ':') {
                        $static = preg_match('~^(["\'])(.+)\1$~s', $current, $quoted) ? $quoted[2] : (preg_match('~^[\w$]+$~', $current) ? $current : null);
                        $key = $isObject ? $depth === 1 : $current === 'find';
                        if ($isObject && $key && $static !== null && in_array($tokens[$cursor - 1], ['{', ','], true)) {
                            $aliases[] = $static;
                        } elseif (! $isObject && $key && $depth === 2 && preg_match('~^(["\'])(.+)\1$~s', $tokens[$cursor + 2] ?? '', $find) && ! str_contains($find[0], '${')) {
                            $aliases[] = $find[2];
                        }
                    }
                }
            }

            return array_values(array_unique($aliases));
        }

        return [];
    }

    /**
     * Keys of `compilerOptions.paths` in tsconfig.json and jsconfig.json, with a trailing `/*` or `*` stripped (`@modules/*` reads
     * `@modules`). Best effort: comments and trailing commas are tolerated; a file that still does not parse adds nothing.
     *
     * @return list<string>
     */
    public static function tsconfigPaths(string $base): array
    {
        $aliases = [];
        foreach (['tsconfig.json', 'jsconfig.json'] as $file) {
            if (! is_file($base.'/'.$file)) {
                continue;
            }
            $json = (string) preg_replace(['~("(?:[^"\\\\]|\\\\.)*")|/\*.*?\*/|//[^\n]*~s', '~,(\s*[}\]])~'], ['$1', '$1'], (string) file_get_contents($base.'/'.$file));
            $data = json_decode($json, true);
            $paths = is_array($data) && is_array($data['compilerOptions']['paths'] ?? null) ? $data['compilerOptions']['paths'] : [];
            foreach (array_keys($paths) as $key) {
                $alias = rtrim(preg_replace('~/?\*$~', '', (string) $key) ?? '', '/');
                if ($alias !== '') {
                    $aliases[] = $alias;
                }
            }
        }

        return array_values(array_unique($aliases));
    }
}
