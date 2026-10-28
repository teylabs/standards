<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Tey\Standards\Conformance;
use Tey\Standards\Configuration;
use Tey\Standards\Enforcement\Maker;
use Tey\Standards\Patterns\TreeCatalog;
use Tey\Standards\Ratchet;

/** Rule `tenant-aware` on a fixture tree: only what it reads (base, source roots, class roots) matters here. */
function tenantConfiguration(string $base, array $options = []): Configuration
{
    $namespace = 'Fixture'.basename($base);

    return new Configuration(...[
        'base' => $base, 'classRoots' => ['src' => $namespace], 'sourceRoots' => ['src'], 'uiRoots' => ['src/UI'], 'uiNamespaces' => [$namespace.'\\UI'], 'hostNamespaces' => [$namespace],
        'controllerRoots' => ['src/Domain/*/Http/Controllers'], 'routeRoots' => ['routes'], 'controllerSuffix' => 'Controller', 'routeDirectory' => 'routes', 'viewModelRoots' => ['src/UI'], 'viewModelBases' => [],
        'typedViewModel' => '', 'viewModelSuffix' => 'ViewModel', 'pageRoots' => ['pages'], 'pageScaffolds' => ['pages'], 'placements' => [], 'retired' => [], 'discoveryRoots' => ['src'],
        'developmentNamespaces' => ['Pest'], 'safeAttributes' => [], 'mediaTrait' => 'X\\MediaTrait', 'mediaInterface' => 'X\\MediaInterface', ...$options,
    ]);
}

/** A scratch app requiring spatie/laravel-multitenancy, with files written under src/. */
function tenantApp(array $files, ?string $multitenancy = null): string
{
    $base = sys_get_temp_dir().'/standards-tenant-'.bin2hex(random_bytes(6)).'/Scratch';
    mkdir($base.'/src', 0777, true);
    file_put_contents($base.'/composer.json', json_encode(['require' => ['spatie/laravel-multitenancy' => '^4.0']]));
    if ($multitenancy !== null) {
        mkdir($base.'/config');
        file_put_contents($base.'/config/multitenancy.php', $multitenancy);
    }
    foreach ($files as $path => $source) {
        is_dir(dirname($base.'/src/'.$path)) || mkdir(dirname($base.'/src/'.$path), 0777, true);
        file_put_contents($base.'/src/'.$path, "<?php\n\nnamespace FixtureScratch;\n\n".$source);
    }
    TenantTemp::$dirs[] = dirname($base);

    return $base;
}

final class TenantTemp
{
    /** @var list<string> */
    public static array $dirs = [];
}

afterEach(function () {
    foreach (TenantTemp::$dirs as $directory) {
        (new Filesystem)->deleteDirectory($directory);
    }
    TenantTemp::$dirs = [];
});

const TENANT_MODEL = "use Illuminate\\Database\\Eloquent\\Model;\nuse Spatie\\Multitenancy\\Models\\Concerns\\UsesTenantConnection;\n\nfinal class Ledger extends Model\n{\n    use UsesTenantConnection;\n}\n";

test('the seeded fixture reports each shape, with file, line and a fix; the clean control reports nothing', function () {
    $bad = (new Conformance(tenantConfiguration(dirname(__DIR__).'/fixtures/Bad')))->violations('tenant-aware');
    $rows = array_map(fn (array $v): array => [$v['target'], $v['check'], $v['line']], $bad);

    expect($rows)->toEqualCanonicalizing([
        ['src/Models/Ledger.php', 'FixtureBad\\Models\\Ledger is a tenant-scoped model that model:prune prunes outside any tenant', 13],
        ['src/Console/Commands/ReconcileLedgersCommand.php', 'FixtureBad\\Console\\Commands\\ReconcileLedgersCommand touches tenant-scoped FixtureBad\\Models\\Ledger without TenantAware', 18],
        ['src/Jobs/SyncLedgersJob.php', 'FixtureBad\\Jobs\\SyncLedgersJob touches tenant-scoped FixtureBad\\Models\\Ledger but is NotTenantAware', 16],
        ['src/Console/Commands/ReconcileLedgersCommand.php', 'FixtureBad\\Console\\Commands\\ReconcileLedgersCommand dispatches from an arrow fn passed to execute(), which loses the tenant', 20],
    ])
        ->and(array_filter($bad, fn (array $v): bool => ! isset($v['hint']) || $v['hint'] === ''))->toBe([])
        ->and((new Conformance(tenantConfiguration(dirname(__DIR__).'/fixtures/Good')))->violations('tenant-aware'))->toBe([]);
});

test('a job is tenant-aware as config/multitenancy.php decides: the default, tenant_aware_jobs and not_tenant_aware_jobs', function () {
    $job = "use Illuminate\\Contracts\\Queue\\ShouldQueue;\n\nfinal class SyncJob implements ShouldQueue\n{\n    public function handle(): void\n    {\n        Ledger::query()->count();\n    }\n}\n";
    $rule = fn (?string $config): array => array_column((new Conformance(tenantConfiguration(tenantApp(['Ledger.php' => TENANT_MODEL, 'SyncJob.php' => $job], $config))))->violations('tenant-aware'), 'check');

    expect($rule(null))->toBe([], 'Spatie\'s default: queues are tenant-aware')
        ->and($rule("<?php\n\nreturn ['queues_are_tenant_aware_by_default' => false];\n"))->toBe(['FixtureScratch\\SyncJob touches tenant-scoped FixtureScratch\\Ledger but is not tenant-aware'])
        ->and($rule("<?php\n\nreturn ['queues_are_tenant_aware_by_default' => false, 'tenant_aware_jobs' => [FixtureScratch\\SyncJob::class]];\n"))->toBe([])
        ->and($rule("<?php\n\nreturn ['queues_are_tenant_aware_by_default' => true, 'not_tenant_aware_jobs' => [FixtureScratch\\SyncJob::class]];\n"))->toBe(['FixtureScratch\\SyncJob touches tenant-scoped FixtureScratch\\Ledger but is not tenant-aware']);
});

test('only Spatie\'s own API counts: an app-side boundary or $tenant->execute in a plain command is still reported', function () {
    $command = "use Illuminate\\Console\\Command;\n\nfinal class PurgeCommand extends Command\n{\n    public function handle(): int\n    {\n        foreach (\\App\\Workspace::all() as \$workspace) {\n            \\Tey\\Kit\\Features\\Workspaces\\TenantBoundary::run(\$workspace->id, fn () => Ledger::query()->delete());\n            \$workspace->execute(function (): void { Ledger::query()->delete(); });\n        }\n\n        return 0;\n    }\n}\n";
    $checks = array_column((new Conformance(tenantConfiguration(tenantApp(['Ledger.php' => TENANT_MODEL, 'PurgeCommand.php' => $command]))))->violations('tenant-aware'), 'check');

    expect($checks)->toBe(['FixtureScratch\\PurgeCommand touches tenant-scoped FixtureScratch\\Ledger without TenantAware']);
});

test('a deliberate cross-tenant admin command is declared in the allowlist, with a reason and an expiry', function () {
    $command = "use Illuminate\\Console\\Command;\n\nfinal class AuditCommand extends Command\n{\n    public function handle(): int\n    {\n        return Ledger::query()->count();\n    }\n}\n";
    $base = tenantApp(['Ledger.php' => TENANT_MODEL, 'AuditCommand.php' => $command]);
    $violations = (new Conformance(tenantConfiguration($base)))->violations('tenant-aware');
    $entry = ['rule' => 'tenant-aware', 'target' => 'src/AuditCommand.php', 'check' => 'FixtureScratch\\AuditCommand touches tenant-scoped FixtureScratch\\Ledger without TenantAware', 'reason' => 'Cross-tenant audit, run by the platform team.', 'date' => '2026-10-28', 'expires' => '2027-04-30'];

    expect((new Ratchet([$entry]))->failures('tenant-aware', array_map(fn (array $v): array => ['target' => $v['target'], 'check' => $v['check']], $violations)))->toBe([]);
});

test('landlord housekeeping is not flagged: prune on a model with no tenant scope; the rule is off without the package', function () {
    $landlord = "use Illuminate\\Database\\Eloquent\\Builder;\nuse Illuminate\\Database\\Eloquent\\Model;\nuse Illuminate\\Database\\Eloquent\\Prunable;\n\nfinal class LoginAttempt extends Model\n{\n    use Prunable;\n\n    public function prunable(): Builder\n    {\n        return static::query()->where('old', true);\n    }\n}\n";
    $base = tenantApp(['LoginAttempt.php' => $landlord]);

    expect((new Conformance(tenantConfiguration($base)))->violations('tenant-aware'))->toBe([]);

    $plain = dirname(__DIR__).'/fixtures/Bad';
    file_put_contents($base.'/composer.json', '{}');
    $base2 = tenantApp(['Ledger.php' => TENANT_MODEL, 'Job.php' => "use Illuminate\\Contracts\\Queue\\ShouldQueue;\nuse Spatie\\Multitenancy\\Jobs\\NotTenantAware;\n\nfinal class Job implements ShouldQueue, NotTenantAware\n{\n    public function handle(): void { Ledger::query()->count(); }\n}\n"]);
    file_put_contents($base2.'/composer.json', '{}');

    expect((new Conformance(tenantConfiguration($base2)))->violations('tenant-aware'))->toBe([], 'without spatie/laravel-multitenancy (and default tenantModels) the rule is off')
        ->and((new Conformance(tenantConfiguration($base2, ['tenantModels' => ['Spatie\\Multitenancy\\Models\\Concerns\\UsesTenantConnection', 'FixtureScratch\\Concerns\\BelongsToWorkspace']])))->violations('tenant-aware'))->not->toBe([], 'an app that names its own tenant traits turns it on');
});

test('an app\'s own tenant-scope trait or base model makes a model tenant-scoped (Configuration::$tenantModels)', function () {
    $base = tenantApp([
        'Concerns/BelongsToWorkspace.php' => "trait BelongsToWorkspace\n{\n}\n",
        'Note.php' => "use Illuminate\\Database\\Eloquent\\Model;\nuse FixtureScratch\\Concerns\\BelongsToWorkspace;\n\nfinal class Note extends Model\n{\n    use BelongsToWorkspace;\n}\n",
        'NotesCommand.php' => "use Illuminate\\Console\\Command;\n\nfinal class NotesCommand extends Command\n{\n    public function handle(): int\n    {\n        return Note::query()->count();\n    }\n}\n",
    ]);
    file_put_contents($base.'/src/Concerns/BelongsToWorkspace.php', "<?php\n\nnamespace FixtureScratch\\Concerns;\n\ntrait BelongsToWorkspace\n{\n}\n");

    expect(array_column((new Conformance(tenantConfiguration($base, ['tenantModels' => ['FixtureScratch\\Concerns\\BelongsToWorkspace']])))->violations('tenant-aware'), 'check'))
        ->toBe(['FixtureScratch\\NotesCommand touches tenant-scoped FixtureScratch\\Note without TenantAware']);
});

test('standards:make makes commands and jobs tenant-aware with Spatie\'s API in a multitenancy app; --landlord opts out and says so', function () {
    $base = fixtureAppForTenancy();
    $calls = [];
    $run = function (string $command, array $arguments) use ($base, &$calls): int {
        $calls[] = $command;
        file_put_contents($base.'/app/Jobs/'.$arguments['name'].'.php', "<?php\n\nnamespace App\\Jobs;\n\nuse Illuminate\\Contracts\\Queue\\ShouldQueue;\nuse Illuminate\\Foundation\\Queue\\Queueable;\n\nclass {$arguments['name']} implements ShouldQueue\n{\n    use Queueable;\n\n    public function handle(): void\n    {\n    }\n}\n");

        return 0;
    };
    $maker = new Maker($base, TreeCatalog::shipped(), generators: ['make:job'], run: $run);

    $maker->make('command', 'BackfillTotals', 'backfill');
    $command = (string) file_get_contents($base.'/app/Console/Commands/BackfillTotalsCommand.php');
    expect($command)->toContain("use Spatie\\Multitenancy\\Commands\\Concerns\\TenantAware;\n")->toContain("{\n    use TenantAware;\n\n")->toContain('{--tenant=* : The tenants to run for (all of them by default)}');

    $maker->make('command', 'PruneLogins', 'maintenance', options: ['landlord']);
    $landlord = (string) file_get_contents($base.'/app/Console/Commands/PruneLoginsCommand.php');
    expect($landlord)->toContain("// Landlord-only (made with --landlord): it runs outside any tenant, so it must not touch tenant-scoped models.\nfinal class PruneLoginsCommand")
        ->not->toContain('TenantAware')->not->toContain('--tenant');

    $maker->make('job', 'SyncLedgers', 'plain');
    expect((string) file_get_contents($base.'/app/Jobs/SyncLedgers.php'))->toContain("use Spatie\\Multitenancy\\Jobs\\TenantAware;\n")->toContain('class SyncLedgers implements ShouldQueue, TenantAware');

    $maker->make('job', 'RotateKeys', 'plain', options: ['landlord']);
    expect((string) file_get_contents($base.'/app/Jobs/RotateKeys.php'))->toContain("use Spatie\\Multitenancy\\Jobs\\NotTenantAware;\n")->toContain('implements ShouldQueue, NotTenantAware')
        ->toContain("// Landlord-only (made with --landlord): it runs outside any tenant, so it must not touch tenant-scoped models.\nclass RotateKeys");

    // Without the package, the command and job come out as before.
    file_put_contents($base.'/composer.json', json_encode(['name' => 'acme/app']));
    $maker->make('command', 'Totals', 'report');
    expect((string) file_get_contents($base.'/app/Console/Commands/TotalsCommand.php'))->not->toContain('TenantAware')->not->toContain('Landlord');
    $maker->make('job', 'Plain', 'plain');
    expect((string) file_get_contents($base.'/app/Jobs/Plain.php'))->toContain('class Plain implements ShouldQueue'."\n");
});

function fixtureAppForTenancy(): string
{
    $base = sys_get_temp_dir().'/standards-tenant-app-'.bin2hex(random_bytes(6));
    (new Filesystem)->copyDirectory(dirname(__DIR__).'/fixtures/App', $base);
    is_dir($base.'/app/Jobs') || mkdir($base.'/app/Jobs', 0777, true);
    $composer = json_decode((string) file_get_contents($base.'/composer.json'), true);
    $composer['require']['spatie/laravel-multitenancy'] = '^4.0';
    file_put_contents($base.'/composer.json', json_encode($composer));
    TenantTemp::$dirs[] = $base;

    return $base;
}
