<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use Tey\Standards\Configuration;
use Tey\Standards\Source;

/**
 * Every PHP file under the test roots and the source roots opens with `<?php`. A file without it is plain text to PHP: Pest prints it and runs nothing, so a whole
 * spec goes dark without a failure. Only what PHP itself allows may come first: a UTF-8 byte-order mark, then a `#!` line (skipped in included files too).
 */
final readonly class PhpTag
{
    public function __construct(private Configuration $config) {}

    /** Whether the file's contents open with the PHP tag, after an optional byte-order mark and shebang line. */
    public static function opens(string $contents): bool
    {
        $contents = str_starts_with($contents, "\xEF\xBB\xBF") ? substr($contents, 3) : $contents;
        if (str_starts_with($contents, '#!')) {
            $end = strpos($contents, "\n");
            $contents = $end === false ? '' : substr($contents, $end + 1);
        }

        return preg_match('/\A<\?php(?:\s|\z)/i', $contents) === 1;
    }

    /** @return list<array{target: string, check: string}> */
    public function violations(): array
    {
        // A configured root that is absent (an app with no tests yet) has nothing to check.
        $roots = array_values(array_unique(array_filter([...$this->config->testRoots, ...$this->config->sourceRoots], fn (string $root): bool => (glob($this->config->base.'/'.$root, GLOB_ONLYDIR) ?: []) !== [])));
        $violations = [];

        foreach ((new Source($this->config))->files($roots) as $path => $contents) {
            if (! self::opens($contents)) {
                $violations[] = ['target' => $path, 'check' => 'does not open with <?php: PHP prints it as text and runs nothing'];
            }
        }

        return $violations;
    }
}
