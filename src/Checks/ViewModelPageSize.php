<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use Tey\Standards\Configuration;

/**
 * [vm.page-size] A page stays small: past LIMIT lines it is doing its children's work, and should be split along its component tree, one nested
 * ViewModel each, handed over whole with v-bind. The size is a proxy: whether the nesting follows the component tree, not the database, is still
 * for review. Lexical, and advisory until an app opts in; existing large pages are baselined and ratcheted down.
 */
final class ViewModelPageSize
{
    public const LIMIT = 500;

    /** @return list<array{target: string, check: string}> */
    public function violations(Configuration $config): array
    {
        $violations = [];
        foreach ((new FrontendSource($config))->appFiles() as $path => $contents) {
            if (basename($path) !== 'Page.vue') {
                continue;
            }
            $lines = substr_count(rtrim($contents, "\n"), "\n") + 1;
            if ($lines > self::LIMIT) {
                $violations[] = ['target' => $path, 'check' => 'page is over '.self::LIMIT.' lines: split it along its component tree, one nested ViewModel each'];
            }
        }

        return $violations;
    }
}
