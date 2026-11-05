<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use Tey\Standards\Configuration;

/**
 * [vm.typed-pages] A page (`Page.vue`) declares its props as its generated ViewModel type, `defineProps<XViewModel>()`, and restates nothing: no inline
 * `{ ... }` type and no untyped `defineProps([...])`. A page with no props is fine. Advisory until an app opts in.
 */
final class ViewModelTypedPages
{
    /** @return list<array{target: string, check: string}> */
    public function violations(Configuration $config): array
    {
        $violations = [];
        foreach ((new FrontendSource($config))->appFiles() as $path => $source) {
            if (basename($path) !== 'Page.vue') {
                continue;
            }
            $script = FrontendSource::script($path, $source);
            if (preg_match('/\bdefineProps\s*(<)?/', $script, $match) !== 1) {
                continue;
            }
            if (! isset($match[1])) {
                $violations[] = ['target' => $path, 'check' => 'page props are untyped: defineProps() without its ViewModel type'];

                continue;
            }
            if (preg_match('/\bdefineProps\s*<\s*([A-Za-z_][\w.]*)\s*>/', $script, $type) !== 1) {
                $violations[] = ['target' => $path, 'check' => 'page props restate a type inline instead of naming the generated ViewModel'];

                continue;
            }
            if (! str_ends_with($type[1], $config->viewModelSuffix)) {
                $violations[] = ['target' => $path, 'check' => 'page props name '.$type[1].', not a generated '.$config->viewModelSuffix.' type'];
            }
        }

        return $violations;
    }
}
