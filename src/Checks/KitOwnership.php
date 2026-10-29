<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use Tey\Standards\Configuration;

/**
 * Frontend files tey/kit owns, which the frontend rules (`descriptors`, `ui plus`, `ui new-tab`, `ui dropzone`, `ui toast`) do not scan:
 *
 * - the kit's copy under `resources/js/components/Kit/`, which `kit:update` replaces;
 * - a file tey/mod records as generated in the app's installed lineage records (`.mod-maintenance/installed/<sha256>.json`, or the
 *   0.8 `.mod-maintenance/installed.json`), such as a kit page under `resources/js/pages/`, while its bytes still equal the recorded
 *   bytes. An edited copy is the app's code and is checked. A record that does not read as tey/mod's installed format names nothing.
 *
 * The record is read, not verified: it only narrows which files are scanned, and an app could as well allowlist the same file.
 */
final class KitOwnership
{
    public const KIT_TREE = 'resources/js/components/Kit/';

    public const RECORDS = '.mod-maintenance/installed';

    public const LEGACY_RECORD = '.mod-maintenance/installed.json';

    /** @param array<string, string> $generated project-relative path => recorded bytes */
    private function __construct(private readonly array $generated) {}

    public static function of(Configuration $config): self
    {
        $records = glob($config->base.'/'.self::RECORDS.'/*.json') ?: [];
        if (is_file($config->base.'/'.self::LEGACY_RECORD)) {
            $records[] = $config->base.'/'.self::LEGACY_RECORD;
        }
        $generated = [];
        foreach ($records as $record) {
            if (is_link($record) || ! preg_match('/\A(?:[a-f0-9]{64}|installed)\.json\z/', basename($record))) {
                continue;
            }
            $data = json_decode((string) file_get_contents($record), true);
            $files = is_array($data) && ($data['format'] ?? null) === 1 && ($data['owner'] ?? null) === 'tey/mod:installed' ? ($data['artifact']['files'] ?? null) : null;
            foreach (is_array($files) ? $files : [] as $path => $encoded) {
                $bytes = is_string($path) && is_string($encoded) ? base64_decode($encoded, true) : false;
                if ($bytes !== false) {
                    $generated[$path] = $bytes;
                }
            }
        }

        return new self($generated);
    }

    public function owns(string $path, string $source): bool
    {
        return str_starts_with($path, self::KIT_TREE) || (isset($this->generated[$path]) && $this->generated[$path] === $source);
    }
}
