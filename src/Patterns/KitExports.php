<?php

declare(strict_types=1);

namespace Tey\Standards\Patterns;

use InvalidArgumentException;

/**
 * The component names tey/kit's `@kit/ui` barrel exports. Validation always
 * uses the shipped snapshot, so CI is deterministic; at runtime an installed
 * kit is compared against it: a kit missing snapshot components warns (recipes
 * may name what it lacks), and a kit that only adds components is an advisory
 * (the snapshot is older than the kit; refresh it on the next standards release).
 */
final class KitExports
{
    public const BARREL = 'resources/features/core/components/Kit/index.ts.stub';

    public static function snapshotFile(): string
    {
        return dirname(__DIR__, 2).'/resources/patterns/kit-exports.json';
    }

    /** @return list<string> */
    public static function snapshot(): array
    {
        $snapshot = json_decode((string) @file_get_contents(self::snapshotFile()), true);

        return is_array($snapshot) && is_array($snapshot['components'] ?? null) ? $snapshot['components'] : [];
    }

    /** @return list<string> component names (capitalised exports) in a barrel's source */
    public static function parse(string $source): array
    {
        $names = [];

        preg_match_all('/export\s*\{([^}]*)\}/', $source, $blocks);

        foreach ($blocks[1] as $block) {
            foreach (explode(',', $block) as $part) {
                $parts = preg_split('/\s+as\s+/', trim($part)) ?: [];
                $name = trim((string) end($parts));

                // Components are PascalCase; an all-caps export is a constant (a reserved type name), not a component.
                if (preg_match('/^[A-Z][A-Za-z0-9]*$/D', $name) === 1 && preg_match('/[a-z]/', $name) === 1) {
                    $names[] = $name;
                }
            }
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /** Rewrites the snapshot from a tey/kit checkout. */
    public static function refresh(string $kit, ?string $target = null): string
    {
        $barrel = rtrim($kit, '/').'/'.self::BARREL;

        if (! is_file($barrel)) {
            throw new InvalidArgumentException("No @kit/ui barrel at {$barrel}; pass a tey/kit checkout.");
        }

        $changelog = (string) @file_get_contents(rtrim($kit, '/').'/CHANGELOG.md');
        $version = preg_match('/^## \[?v?(\d+\.\d+\.\d+)/m', $changelog, $match) === 1 ? ' v'.$match[1] : '';
        $components = self::parse((string) file_get_contents($barrel));
        $snapshot = ['source' => 'tey/kit '.self::BARREL.' (the @kit/ui barrel)'.$version, 'module' => '@kit/ui', 'components' => $components];
        file_put_contents($target ?? self::snapshotFile(), json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

        return count($components).' components from '.$snapshot['source'];
    }

    /** A warning when the app's installed kit lacks components the snapshot names (recipes may name them), else null. */
    public static function mismatch(string $base): ?string
    {
        [$missing, $extra] = self::compare($base);

        if ($missing === []) {
            return null;
        }

        return 'The installed tey/kit exports differ from the pattern catalog\'s snapshot'
            .($missing === [] ? '' : '; missing: '.implode(', ', $missing))
            .($extra === [] ? '' : '; not in the snapshot: '.implode(', ', $extra))
            .'. Recipes may name components this kit lacks; update tey/standards or tey/kit.';
    }

    /** An advisory when the installed kit only adds components (a superset of the snapshot): the snapshot is older than the kit, not drift. */
    public static function lag(string $base): ?string
    {
        [$missing, $extra] = self::compare($base);

        if ($missing !== [] || $extra === []) {
            return null;
        }

        return 'The pattern catalog\'s kit snapshot is older than the installed tey/kit (new: '.implode(', ', $extra).'). Nothing is missing; recipes just won\'t suggest those yet. A newer tey/standards refreshes it.';
    }

    /** @return array{0: list<string>, 1: list<string>} [in the snapshot but not installed, installed but not in the snapshot]; both empty without an installed kit */
    private static function compare(string $base): array
    {
        $barrel = $base.'/vendor/tey/kit/'.self::BARREL;

        if (! is_file($barrel)) {
            return [[], []];
        }

        $installed = self::parse((string) file_get_contents($barrel));
        $snapshot = self::snapshot();

        return [array_values(array_diff($snapshot, $installed)), array_values(array_diff($installed, $snapshot))];
    }
}
