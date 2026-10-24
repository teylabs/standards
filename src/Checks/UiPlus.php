<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use Tey\Standards\Configuration;

final class UiPlus
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
                $label = FrontendSource::attribute($action['attributes'], 'label') ?? trim(strip_tags(preg_replace('~\{\{.*?\}\}~s', '', $action['inner']) ?? ''));
                if (preg_match('/^(Add|New|Create)\b/', $label) && ! FrontendSource::hasIcon($action['attributes'].' '.$action['inner'], $config->uiPlusIcons)) {
                    $violations[] = ['target' => $path, 'check' => 'add action needs plus icon: '.$label];
                }
            }
        }

        return array_values(array_unique($violations, SORT_REGULAR));
    }
}
