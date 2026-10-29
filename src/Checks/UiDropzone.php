<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use Tey\Standards\Configuration;

final class UiDropzone
{
    /** @return list<array{target: string, check: string}> */
    public function violations(Configuration $config): array
    {
        $violations = [];
        foreach ((new FrontendSource($config))->appFiles() as $path => $source) {
            if ($path === $config->uiDropzone || ! str_ends_with($path, '.vue')) {
                continue;
            }
            preg_match_all('~<input\b((?:"[^"]*"|\'[^\']*\'|[^\'">])*)>~si', FrontendSource::template($source), $matches);
            foreach ($matches[1] as $attributes) {
                if (strtolower(FrontendSource::attribute($attributes, 'type') ?? '') === 'file' || preg_match('~(?:^|\s):type\s*=\s*(?:"\'file\'"|\'"file"\')~', $attributes)) {
                    $violations[] = ['target' => $path, 'check' => 'file input outside configured dropzone'];
                }
            }
        }

        return array_values(array_unique($violations, SORT_REGULAR));
    }
}
