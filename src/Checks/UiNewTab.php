<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use Tey\Standards\Configuration;
use Tey\Standards\Enforcement\HostShape;

/**
 * A new-tab action carries an exact `noopener` rel token and an external icon. Two kit shapes pass the rel half: a kit component (imported from
 * `@/components/Kit` or the `@kit/ui` alias) renders `rel="noopener noreferrer"` itself whenever its `target` is `_blank`, so the tag needs no rel of its
 * own; the external icon is still required. Files tey/kit owns (KitOwnership) are not scanned: `kit:update` replaces them, and the kit's row
 * and menu actions label new-tab links by their action label rather than an icon.
 */
final class UiNewTab
{
    /** @deprecated use KitOwnership::KIT_TREE */
    public const KIT_TREE = KitOwnership::KIT_TREE;

    /** @return list<array{target: string, check: string}> */
    public function violations(Configuration $config): array
    {
        $violations = [];
        foreach ((new FrontendSource($config))->appFiles() as $path => $source) {
            if (! str_ends_with($path, '.vue')) {
                continue;
            }
            $kit = self::kitComponents(FrontendSource::script($path, $source));
            foreach (FrontendSource::actions($source, $config) as $action) {
                if (FrontendSource::attribute($action['attributes'], 'target') !== '_blank') {
                    continue;
                }
                $rel = preg_split('/\s+/', trim(FrontendSource::attribute($action['attributes'], 'rel') ?? '')) ?: [];
                $noopener = in_array('noopener', $rel, true) || in_array($action['tag'], $kit, true);
                if (! $noopener || ! FrontendSource::hasIcon($action['attributes'].' '.$action['inner'], [...$config->uiExternalIcons, ...$config->uiExternalLabelComponents])) {
                    $violations[] = ['target' => $path, 'check' => 'new-tab action needs noopener and external icon'];
                }
            }
        }

        return array_values(array_unique($violations, SORT_REGULAR));
    }

    /**
     * The local names a script binds from the kit: default and named imports from `@/components/Kit` (the barrel or any file under it) or `@kit/ui`.
     *
     * @return list<string>
     */
    public static function kitComponents(string $script): array
    {
        preg_match_all('~\bimport\s+(?!type\b)([^;]*?)\s+from\s+([\'"])([^\'"]+)\2~s', $script, $matches, PREG_SET_ORDER);
        $names = [];
        foreach ($matches as $match) {
            $from = $match[3];
            if ($from !== HostShape::KIT_INDEX && ! str_starts_with($from, HostShape::KIT_INDEX.'/') && $from !== '@kit/ui' && ! str_starts_with($from, '@kit/ui/')) {
                continue;
            }
            $clause = $match[1];
            if (preg_match('~\{([^}]*)\}~', $clause, $named)) {
                foreach (explode(',', $named[1]) as $binding) {
                    if (preg_match('~^\s*[\w$]+(?:\s+as\s+([\w$]+))?\s*$~', $binding, $parts)) {
                        $names[] = $parts[1] ?? trim($binding);
                    }
                }
                $clause = str_replace($named[0], '', $clause);
            }
            if (preg_match('~^\s*([\w$]+)~', $clause, $default)) {
                $names[] = $default[1];
            }
        }

        return array_values(array_unique($names));
    }
}
