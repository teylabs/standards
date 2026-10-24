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
        $viteAliases = $this->viteAliases($config);
        $violations = [];
        foreach ($files as $path => $source) {
            foreach (FrontendSource::imports(FrontendSource::script($path, $source)) as $specifier) {
                if (str_starts_with($specifier, '.') || str_starts_with($specifier, 'node:') || array_any($config->frontendAliases, fn (string $alias): bool => str_starts_with($specifier, $alias)) || array_any($viteAliases, fn (string $alias): bool => $specifier === $alias || str_starts_with($specifier, rtrim($alias, '/').'/'))) {
                    continue;
                }

                $name = implode('/', array_slice(explode('/', $specifier), 0, str_starts_with($specifier, '@') ? 2 : 1));
                if (! in_array($name, $declared, true)) {
                    $violations[] = ['target' => $path, 'check' => 'undeclared package import: '.$specifier];
                }
            }
        }

        return $violations;
    }

    /**
     * Static keys of `resolve.alias` in vite.config.ts/js: object keys, or `find`
     * strings of an array literal. Computed keys, spreads, regular expressions
     * and aliases held in variables are ignored.
     *
     * @return list<string>
     */
    private function viteAliases(Configuration $config): array
    {
        foreach (['vite.config.ts', 'vite.config.js'] as $file) {
            if (! is_file($config->base.'/'.$file)) {
                continue;
            }
            $tokens = FrontendSource::tokens((string) file_get_contents($config->base.'/'.$file));
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
}
