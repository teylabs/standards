<?php

declare(strict_types=1);

namespace Tey\Standards\Telemetry;

use Composer\InstalledVersions;
use Tey\Standards\Conformance;
use Tey\Standards\Enforcement\Gate;
use Throwable;

/** Who ran it, which standards and which ruleset: read from the environment and the install, never prompted. */
final class Context
{
    /**
     * Attribution from the environment only. Solo sets SOLO_PROCESS_ID and SOLO_PROJECT_ID; Claude Code sets CLAUDE_CODE_SESSION_ID; the
     * model is recorded when ANTHROPIC_MODEL or CLAUDE_MODEL is set. A missing variable is null.
     *
     * @param  array<string, string|false>|null  $environment
     * @return array{solo_process_id: ?string, solo_project_id: ?string, session: ?string, model: ?string}
     */
    public static function who(?array $environment = null): array
    {
        $read = function (string ...$names) use ($environment): ?string {
            foreach ($names as $name) {
                $value = $environment === null ? getenv($name) : ($environment[$name] ?? false);

                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }

            return null;
        };

        return [
            'solo_process_id' => $read('SOLO_PROCESS_ID'),
            'solo_project_id' => $read('SOLO_PROJECT_ID'),
            'session' => $read('CLAUDE_CODE_SESSION_ID'),
            'model' => $read('ANTHROPIC_MODEL', 'CLAUDE_MODEL'),
        ];
    }

    /** @param array<string, string|false>|null $environment */
    public static function source(?array $environment = null): string
    {
        $value = fn (string $name): string|false => $environment === null ? getenv($name) : ($environment[$name] ?? false);

        return in_array(strtolower((string) $value('CI')), ['1', 'true'], true) || $value('GITHUB_ACTIONS') === 'true' ? 'ci' : 'local';
    }

    public static function version(): ?string
    {
        try {
            return InstalledVersions::isInstalled('tey/standards') ? InstalledVersions::getPrettyVersion('tey/standards') : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A short hash of the ruleset: every rule id, the advisory and optional lists, and the bytes of the app's configuration file (which
     * rules it opts into). The allowlist and the package version are not part of it (the version is recorded beside it). Rates are compared within one ruleset, so a new rule does not read as a
     * regression.
     */
    public static function ruleset(string $base, string $configurationFile): string
    {
        $path = str_starts_with($configurationFile, '/') ? $configurationFile : $base.'/'.$configurationFile;
        $rules = array_keys(Conformance::TESTS);
        sort($rules);

        return substr(hash('sha256', json_encode([
            'rules' => $rules,
            'advisory' => Gate::ADVISORY,
            'optional' => Gate::OPTIONAL,
            'configuration' => is_file($path) ? hash_file('sha256', $path) : null,
        ], JSON_THROW_ON_ERROR)), 0, 16);
    }
}
