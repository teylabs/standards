<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use Tey\Standards\Configuration;

final class UiPlus
{
    /** Link tags that are only an add button when styled as one; a Button wrapping one with as-child is read as the Button. */
    private const LINKS = ['Link', 'a', 'ModalLink'];

    /** @return list<array{target: string, check: string}> */
    public function violations(Configuration $config): array
    {
        $violations = [];
        foreach ((new FrontendSource($config))->appFiles() as $path => $source) {
            if (! str_ends_with($path, '.vue')) {
                continue;
            }
            foreach (FrontendSource::actions($source, $config) as $action) {
                if (in_array($action['tag'], self::LINKS, true) && ! self::buttonStyled($action['attributes'])) {
                    continue;
                }
                $label = FrontendSource::attribute($action['attributes'], 'label') ?? trim(strip_tags(preg_replace('~\{\{.*?\}\}~s', '', $action['inner']) ?? ''));
                if (preg_match('/^(Add|New|Create)\b/', $label) && ! FrontendSource::hasIcon($action['attributes'].' '.$action['inner'], $config->uiPlusIcons)) {
                    $violations[] = ['target' => $path, 'check' => 'add action needs plus icon: '.$label];
                }
            }
        }

        return array_values(array_unique($violations, SORT_REGULAR));
    }

    /** A `variant` attribute (bound or not) or a `buttonVariants(...)` class; read attribute by attribute, so text inside another value never counts. */
    private static function buttonStyled(string $attributes): bool
    {
        preg_match_all('~([^\s=/>]+)(?:\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+))?~', $attributes, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $name = preg_replace('~^(?::|v-bind:)~', '', $match[1]);
            if ($name === 'variant' || ($name === 'class' && preg_match('~\bbuttonVariants\s*\(~', $match[2] ?? ''))) {
                return true;
            }
        }

        return false;
    }
}
