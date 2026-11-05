<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use Tey\Standards\Configuration;

/**
 * [vm.nested-reach] A page does not reach into its structured props: a prop whose generated type is an object or a list (a nested ViewModel, a Data
 * object, an array) is handed whole to the child that draws it, `<Child v-bind="prop" />`, and the page reads none of its fields (`prop.field` in the
 * template, `props.prop.field` in the script). `v-if="prop"`, `v-for="row in prop"` and passing it whole are fine. The prop list comes from the page's
 * generated ViewModel type (`defineProps<XViewModel>()`), found in the frontend sources; a page whose type is not found is not judged. Lexical, and
 * advisory until an app opts in.
 */
final class ViewModelNestedReach
{
    private const SCALARS = '/^(string|number|boolean|null|undefined|any|unknown|true|false|\'[^\']*\'|"[^"]*"|-?\d+(\.\d+)?)$/';

    /** @return list<array{target: string, check: string}> */
    public function violations(Configuration $config): array
    {
        $source = new FrontendSource($config);
        $types = self::types($source->files());
        $violations = [];
        foreach ($source->appFiles() as $path => $contents) {
            if (basename($path) !== 'Page.vue') {
                continue;
            }
            $script = FrontendSource::script($path, $contents);
            if (preg_match('/\bdefineProps\s*<\s*(?:[\w]+\.)*(\w+)\s*>/', $script, $match) !== 1 || ! isset($types[$match[1]])) {
                continue;
            }
            $template = FrontendSource::template($contents);
            foreach ($types[$match[1]] as $prop) {
                $name = preg_quote($prop, '/');
                preg_match_all('/(?<![\w.$])'.$name.'\s*\??\.\s*([A-Za-z_$][\w$]*)/', $template, $inTemplate);
                preg_match_all('/\bprops\s*\??\.\s*'.$name.'\s*\??\.\s*([A-Za-z_$][\w$]*)/', $script, $inScript);
                $fields = array_values(array_unique([...$inTemplate[1], ...$inScript[1]]));
                sort($fields);
                if ($fields !== []) {
                    $violations[] = ['target' => $path, 'check' => 'page reads into its prop '.$prop.' ('.implode(', ', $fields).'): hand it whole to a child with v-bind'];
                }
            }
        }

        return $violations;
    }

    /**
     * Each generated `export type XViewModel = { ... }` (or `interface`) mapped to its structured fields: those whose type is not a scalar or a union of scalars.
     *
     * @param  array<string, string>  $files
     * @return array<string, list<string>>
     */
    private static function types(array $files): array
    {
        $types = [];
        foreach ($files as $path => $contents) {
            if (! str_ends_with($path, '.ts') || ! str_contains($contents, 'ViewModel')) {
                continue;
            }
            preg_match_all('/export\s+(?:type\s+(\w+)\s*=|interface\s+(\w+))\s*\{(.*?)\n\s*\}/s', $contents, $blocks, PREG_SET_ORDER);
            foreach ($blocks as $block) {
                $name = $block[1] !== '' ? $block[1] : $block[2];
                $fields = [];
                preg_match_all('/^\s*([A-Za-z_$][\w$]*)\??\s*:\s*([^\n]+?);?\s*$/m', $block[3], $lines, PREG_SET_ORDER);
                foreach ($lines as [, $field, $type]) {
                    $parts = array_map('trim', explode('|', trim($type)));
                    if (array_filter($parts, fn (string $part): bool => preg_match(self::SCALARS, $part) !== 1) !== []) {
                        $fields[] = $field;
                    }
                }
                $types[$name] = $fields;
            }
        }

        return $types;
    }
}
