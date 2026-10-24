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
        $violations = [];
        foreach ($files as $path => $source) {
            foreach (FrontendSource::imports(FrontendSource::script($path, $source)) as $specifier) {
                if (str_starts_with($specifier, '.') || str_starts_with($specifier, 'node:') || array_any($config->frontendAliases, fn (string $alias): bool => str_starts_with($specifier, $alias))) {
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
}
