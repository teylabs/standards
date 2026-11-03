<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use Tey\Standards\Configuration;

/**
 * A page's way back up is tey/kit's "Back to <parent>" strip, not a link the page writes itself. A breakout page (one that leaves its parent's shared
 * layout) declares it through its ViewModel's `breaksOut()` or its route's `kit.breakout` default, and the kit derives the strip from the trail's parent
 * crumb, in place of the trail. Flagged: a configured action tag that navigates (it has an `href` or `:href`) and whose whole label is "Back" or
 * "Back to …", with or without a leading arrow. A "Back" button with no href (a wizard's previous step) is not a way up and is left alone. Files tey/kit
 * owns are not scanned.
 */
final class UiBackStrip
{
    private const LABEL = '~^(?:←|&larr;|‹|&lsaquo;|<|«)?\s*back(?:\s+to\b.*)?$~iu';

    /** @return list<array{target: string, check: string}> */
    public function violations(Configuration $config): array
    {
        $violations = [];
        foreach ((new FrontendSource($config))->appFiles() as $path => $source) {
            if (! str_ends_with($path, '.vue')) {
                continue;
            }
            foreach (FrontendSource::actions($source, $config) as $action) {
                if (FrontendSource::attribute($action['attributes'], 'href') === null && FrontendSource::attribute($action['attributes'], ':href') === null
                    && FrontendSource::attribute($action['attributes'], 'v-bind:href') === null) {
                    continue;
                }
                $label = FrontendSource::attribute($action['attributes'], 'label') ?? trim(html_entity_decode(strip_tags((string) preg_replace('~\{\{.*?\}\}~s', ' x ', $action['inner']))));
                $label = (string) preg_replace('~\s+~u', ' ', trim($label));
                if ($label !== '' && preg_match(self::LABEL, $label) === 1) {
                    $violations[] = ['target' => $path, 'check' => 'hand-written back link: declare the page a breakout (breaksOut() or the kit.breakout route default) and let the kit draw "Back to <parent>"'];
                }
            }
        }

        return array_values(array_unique($violations, SORT_REGULAR));
    }
}
