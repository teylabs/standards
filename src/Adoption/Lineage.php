<?php

declare(strict_types=1);

namespace Tey\Standards\Adoption;

use Tey\Standards\Checks\KitOwnership;

/**
 * The installed tey/mod lineage records under `.mod-maintenance/installed/*.json` (and the legacy `.mod-maintenance/installed.json`), the
 * same records `kit:status` and KitOwnership read, as static adoption evidence. A record is `{format: 1, owner: "tey/mod:installed",
 * artifact: {name: "<composer>:<feature>", files: {path: base64 bytes}, metadata: {adopt?: {stamp, tables}}}}`; tey/kit writes
 * `metadata.adopt` (Lineages::adoption) when the workspaces lineage is installed into a host that owns its workspaces (or permission) tables.
 *
 * A record names a feature installed. Its tables are the ones `metadata.adopt.tables` adopts in place (`permission` stands for
 * spatie/laravel-permission's five default table names) plus the ones its recorded migration files create. A record that can't be read,
 * isn't JSON, or has a malformed `artifact` or `metadata.adopt` is treated as absent and reported in `problems`.
 */
final class Lineage
{
    /** What kit's `permission` adoption entry stands for: spatie/laravel-permission's default table names. */
    public const PERMISSION_TABLES = ['permissions', 'roles', 'model_has_permissions', 'model_has_roles', 'role_has_permissions'];

    /**
     * @param  array<string, string>  $features  "<composer>:<feature>" => record path (project-relative)
     * @param  array<string, array{lineage: string, record: string, how: string}>  $tables  table => the lineage that adopts or creates it
     * @param  list<string>  $problems  project-relative paths of records treated as absent, with why
     */
    private function __construct(public readonly array $features, public readonly array $tables, public readonly array $problems) {}

    public static function read(string $base): self
    {
        $records = glob($base.'/'.KitOwnership::RECORDS.'/*.json') ?: [];
        if (is_file($base.'/'.KitOwnership::LEGACY_RECORD)) {
            $records[] = $base.'/'.KitOwnership::LEGACY_RECORD;
        }
        sort($records);
        $features = [];
        $tables = [];
        $problems = [];
        foreach ($records as $record) {
            $relative = substr($record, strlen($base) + 1);
            $data = is_link($record) || ! is_readable($record) ? null : json_decode((string) file_get_contents($record), true);
            if (! is_array($data)) {
                $problems[] = $relative.' (unreadable or not JSON)';

                continue;
            }
            if (($data['format'] ?? null) !== 1 || ($data['owner'] ?? null) !== 'tey/mod:installed') {
                continue;
            }
            $artifact = $data['artifact'] ?? null;
            $name = is_array($artifact) ? ($artifact['name'] ?? null) : null;
            if (! is_string($name) || preg_match('~\A[a-z0-9_.-]+/[a-z0-9_.-]+:[a-z0-9-]+\z~', $name) !== 1 || ! is_array($artifact['files'] ?? [])) {
                $problems[] = $relative.' (no valid artifact name)';

                continue;
            }
            $adopt = $artifact['metadata']['adopt'] ?? null;
            if ($adopt !== null && (! is_array($adopt) || ! is_string($adopt['stamp'] ?? null) || preg_match('/\A\d{4}_\d{2}_\d{2}_\d{6}\z/', $adopt['stamp']) !== 1 || ! is_array($adopt['tables'] ?? null))) {
                $problems[] = $relative.' (malformed metadata.adopt)';

                continue;
            }
            $features[$name] = $relative;
            foreach ($adopt === null ? [] : array_filter($adopt['tables'], 'is_string') as $table) {
                foreach ($table === 'permission' ? self::PERMISSION_TABLES : [$table] as $one) {
                    $tables[$one] = ['lineage' => $name, 'record' => $relative, 'how' => 'adopted in place'];
                }
            }
            foreach ($artifact['files'] ?? [] as $path => $encoded) {
                $bytes = is_string($path) && str_starts_with($path, 'database/migrations/') && is_string($encoded) ? base64_decode($encoded, true) : false;
                foreach ($bytes === false ? [] : Code::createdTables($bytes) as $created) {
                    $tables[$created['table']] ??= ['lineage' => $name, 'record' => $relative, 'how' => 'created'];
                }
            }
        }

        return new self($features, $tables, $problems);
    }

    public function installed(string $package, string $feature): bool
    {
        return isset($this->features[$package.':'.$feature]);
    }

    /** The note a row carries when some lineage record was treated as absent; null when every record read. */
    public function note(): ?string
    {
        return $this->problems === [] ? null : 'lineage record treated as absent: '.implode(', ', $this->problems);
    }
}
