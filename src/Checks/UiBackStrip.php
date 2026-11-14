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
 *
 * A guest or auth card has no breakout strip, so its back links ("Back to sign in") are left alone: a page under a `pages/Auth/` or `pages/Guest/`
 * directory (the host's layout callback puts kit's auth pages in its GuestShell; Breeze and the starter kits keep theirs there too), and a file whose
 * template renders, or whose `layout:` option names, a guest or auth layout or card (`GuestShell`, `GuestLayout`, `AuthLayout`, `AuthCard`,
 * `AuthenticationCard`, `AuthBase`, or their kebab-case tags). `AuthenticatedLayout` is the signed-in layout and does not count.
 */
final class UiBackStrip
{
    private const LABEL = '~^(?:←|&larr;|‹|&lsaquo;|<|«)?\s*back(?:\s+to\b.*)?$~iu';

    private const GUEST_PAGE = '~(?:^|/)[Pp]ages/(?:[Aa]uth|[Gg]uest)/~';

    private const GUEST_TAG = '~<(?:(?:Guest|Auth(?!enticated))\w*(?:Shell|Layout|Card|Base)|(?:guest|auth(?!enticated))[\w-]*-(?:shell|layout|card|base))[\s/>]~';

    private const GUEST_LAYOUT = '~\blayout\s*:\s*(?:Guest|Auth(?!enticated))\w*(?:Shell|Layout|Card|Base)\b~';

    /** @return list<array{target: string, check: string}> */
    public function violations(Configuration $config): array
    {
        $violations = [];
        foreach ((new FrontendSource($config))->appFiles() as $path => $source) {
            if (! str_ends_with($path, '.vue') || preg_match(self::GUEST_PAGE, $path) === 1
                || preg_match(self::GUEST_TAG, FrontendSource::template($source)) === 1 || preg_match(self::GUEST_LAYOUT, FrontendSource::script($path, $source)) === 1) {
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
