<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use Tey\Standards\Configuration;

final class UiNewTab
{
    /** @return list<array{target: string, check: string}> */
    public function violations(Configuration $config): array
    {
        $violations = [];
        foreach ((new FrontendSource($config))->files() as $path => $source) {
            if (! str_ends_with($path, '.vue')) {
                continue;
            }
            foreach (FrontendSource::actions($source, $config) as $action) {
                if (FrontendSource::attribute($action['attributes'], 'target') !== '_blank') {
                    continue;
                }
                $rel = preg_split('/\s+/', trim(FrontendSource::attribute($action['attributes'], 'rel') ?? '')) ?: [];
                if (! in_array('noopener', $rel, true) || ! FrontendSource::hasIcon($action['attributes'].' '.$action['inner'], $config->uiExternalIcons)) {
                    $violations[] = ['target' => $path, 'check' => 'new-tab action needs noopener and external icon'];
                }
            }
        }

        return array_values(array_unique($violations, SORT_REGULAR));
    }
}
