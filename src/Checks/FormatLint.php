<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use Tey\Standards\Configuration;

/**
 * Rule `format-lint` (optional): an app with a package.json formats and lints its frontend with the house config (Prettier and ESLint, Jasper 2026-11-05:
 * "the vue markup is ugly to read through"), and CI enforces both. It checks presence, not the config's contents: the scripts, the config files and a CI step.
 */
final readonly class FormatLint
{
    private const HINT = 'Copy the house config from tey/starter (.prettierrc, .prettierignore, eslint.config.js, .editorconfig and the format/lint scripts), build the ignore lists from php artisan kit:list, then run npm run format:check and npm run lint:check in CI.';

    public function __construct(private Configuration $config) {}

    /** @return list<array{target: string, check: string, hint?: string}> */
    public function violations(): array
    {
        $base = $this->config->base;
        if (! is_file($base.'/package.json')) {
            return [];
        }
        $package = json_decode((string) file_get_contents($base.'/package.json'), true);
        $scripts = is_array($package) && is_array($package['scripts'] ?? null) ? $package['scripts'] : [];

        $violations = [];
        foreach (['format:check' => 'prettier', 'lint:check' => 'eslint'] as $script => $tool) {
            if (! is_string($scripts[$script] ?? null) || ! str_contains($scripts[$script], $tool)) {
                $violations[] = ['target' => 'package.json', 'check' => "package.json has no \"$script\" script running $tool", 'hint' => self::HINT];
            }
        }
        if (glob($base.'/{.prettierrc,.prettierrc.json,.prettierrc.js,.prettierrc.cjs,.prettierrc.mjs,prettier.config.js,prettier.config.mjs}', GLOB_BRACE) === []) {
            $violations[] = ['target' => '.prettierrc', 'check' => 'no Prettier config', 'hint' => self::HINT];
        }
        if (glob($base.'/eslint.config.{js,mjs,cjs,ts}', GLOB_BRACE) === []) {
            $violations[] = ['target' => 'eslint.config.js', 'check' => 'no ESLint flat config', 'hint' => self::HINT];
        }
        $ci = implode("\n", array_map(fn (string $file): string => (string) file_get_contents($file), glob($base.'/.github/workflows/*.{yml,yaml}', GLOB_BRACE) ?: []));
        foreach (['format:check', 'lint:check'] as $script) {
            if (preg_match('~npm run '.preg_quote($script, '~').'\b~', $ci) !== 1) {
                $violations[] = ['target' => '.github/workflows', 'check' => "CI does not run npm run $script", 'hint' => self::HINT];
            }
        }

        return $violations;
    }
}
