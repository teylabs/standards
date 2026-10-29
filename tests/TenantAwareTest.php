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

test('a command scoped to one tenant from an argument, failing on a missing one, passes when all its work runs inside run/execute', function (string $body, bool $passes) {
    $workspace = "use Illuminate\\Database\\Eloquent\\Model;\n\nfinal class Workspace extends Model\n{\n    public function run(callable \$callback): mixed { return \$callback(); }\n}\n";
    $command = "use Illuminate\\Console\\Command;\n\nfinal class SyncCommand extends Command\n{\n    protected \$signature = 'sync {workspace} {--force}';\n\n    public function handle(): int\n    {\n        {$body}\n    }\n}\n";
    $checks = array_column((new Conformance(tenantConfiguration(tenantApp(['Ledger.php' => TENANT_MODEL, 'Workspace.php' => $workspace, 'SyncCommand.php' => $command]))))->violations('tenant-aware'), 'check');

    expect($checks)->toBe($passes ? [] : ['FixtureScratch\\SyncCommand touches tenant-scoped FixtureScratch\\Ledger without TenantAware']);
})->with([
    'findOrFail then run' => ['$workspace = Workspace::findOrFail($this->argument(\'workspace\')); $this->info(\'Syncing\'); $workspace->run(fn () => Ledger::query()->count()); return self::SUCCESS;', true],
    'firstOrFail on an option, execute with a block' => ['$slug = (string) $this->option(\'force\'); $workspace = Workspace::where(\'slug\', $slug)->firstOrFail(); $workspace->execute(function (): void { Ledger::query()->delete(); }); return 0;', true],
    'find with a failing null check' => ['$workspace = Workspace::find($this->argument(\'workspace\')); if (! $workspace) {     $this->error(\'No such workspace\');     return self::FAILURE; } return $workspace->run(fn () => Ledger::query()->count());', true],
    'find with a throwing null check' => ['$workspace = Workspace::find($this->argument(\'workspace\')); if ($workspace === null) {     throw new \\RuntimeException(\'missing\'); } $workspace->run(fn () => Ledger::query()->count()); return 0;', true],
    'chained findOrFail()->run' => ['return Workspace::findOrFail($this->argument(\'workspace\'))->run(fn () => Ledger::query()->count());', true],
    'find without a null check' => ['$workspace = Workspace::find($this->argument(\'workspace\')); $workspace->run(fn () => Ledger::query()->count()); return 0;', false],
    'chained find()->run' => ['return Workspace::find($this->argument(\'workspace\'))->run(fn () => Ledger::query()->count());', false],
    'nullsafe run' => ['return Workspace::find($this->argument(\'workspace\'))?->run(fn () => Ledger::query()->count());', false],
    'a null check that does not fail' => ['$workspace = Workspace::find($this->argument(\'workspace\')); if (! $workspace) {     $this->error(\'No such workspace\'); } $workspace->run(fn () => Ledger::query()->count()); return 0;', false],
    'tenant work outside the callback' => ['$workspace = Workspace::findOrFail($this->argument(\'workspace\')); Ledger::query()->delete(); $workspace->run(fn () => Ledger::query()->count()); return 0;', false],
    'other work outside the callback' => ['$workspace = Workspace::findOrFail($this->argument(\'workspace\')); \\Illuminate\\Support\\Facades\\DB::table(\'ledgers\')->delete(); $workspace->run(fn () => Ledger::query()->count()); return 0;', false],
    'work after the callback' => ['$workspace = Workspace::findOrFail($this->argument(\'workspace\')); $workspace->run(fn () => 1); Ledger::query()->delete(); return 0;', false],
    'tenant not from an argument' => ['$workspace = Workspace::query()->firstOrFail(); $workspace->run(fn () => Ledger::query()->count()); return 0;', false],
    'tenant from config' => ['$workspace = Workspace::findOrFail(config(\'app.workspace\')); $workspace->run(fn () => Ledger::query()->count()); return 0;', false],
    'output that calls something' => ['$workspace = Workspace::findOrFail($this->argument(\'workspace\')); $this->info(Ledger::query()->count()); $workspace->run(fn () => 1); return 0;', false],
    'never runs' => ['$workspace = Workspace::findOrFail($this->argument(\'workspace\')); return Ledger::query()->count();', false],
]);

test('an argument-scoped command still fails when another method touches a tenant model outside the callback', function () {
    $command = "use Illuminate\\Console\\Command;\n\nfinal class SyncCommand extends Command\n{\n    public function handle(): int\n    {\n        \\App\\Workspace::findOrFail(\$this->argument('workspace'))->run(fn () => \$this->sync());\n        return 0;\n    }\n\n    private function sync(): int\n    {\n        return Ledger::query()->count();\n    }\n}\n";
    $checks = array_column((new Conformance(tenantConfiguration(tenantApp(['Ledger.php' => TENANT_MODEL, 'SyncCommand.php' => $command]))))->violations('tenant-aware'), 'check');

    expect($checks)->toBe(['FixtureScratch\\SyncCommand touches tenant-scoped FixtureScratch\\Ledger without TenantAware']);
});

test('a deliberate cross-tenant admin command is declared in the allowlist, with a reason and an expiry', function () {
    $command = "use Illuminate\\Console\\Command;\n\nfinal class AuditCommand extends Command\n{\n    public function handle(): int\n    {\n        return Ledger::query()->count();\n    }\n}\n";
    $base = tenantApp(['Ledger.php' => TENANT_MODEL, 'AuditCommand.php' => $command]);
    $violations = (new Conformance(tenantConfiguration($base)))->violations('tenant-aware');
    $entry = ['rule' => 'tenant-aware', 'target' => 'src/AuditCommand.php', 'check' => 'FixtureScratch\\AuditCommand touches tenant-scoped FixtureScratch\\Ledger without TenantAware', 'reason' => 'Cross-tenant audit, run by the platform team.', 'date' => '2026-10-28', 'expires' => '2027-04-30'];

    expect((new Ratchet([$entry]))->failures('tenant-aware', array_map(fn (array $v): array => ['target' => $v['target'], 'check' => $v['check']], $violations)))->toBe([]);
});

test('a landlord command is declared in Configuration::$landlordCommands with a reason; nothing else is skipped', function () {
    $command = fn (string $name): string => "use Illuminate\\Console\\Command;\n\nfinal class {$name} extends Command\n{\n    public function handle(): int\n    {\n        return Ledger::query()->count();\n    }\n}\n";
    $base = tenantApp(['Ledger.php' => TENANT_MODEL, 'CreateOwnerCommand.php' => $command('CreateOwnerCommand'), 'InviteCommand.php' => $command('InviteCommand'), 'ReportCommand.php' => $command('ReportCommand')]);
    $run = fn (array $landlord): array => array_column((new Conformance(tenantConfiguration($base, ['landlordCommands' => $landlord])))->violations('tenant-aware'), 'check');
    $touch = fn (string $name): string => "FixtureScratch\\{$name} touches tenant-scoped FixtureScratch\\Ledger without TenantAware";

    expect($run([]))->toEqualCanonicalizing([$touch('CreateOwnerCommand'), $touch('InviteCommand'), $touch('ReportCommand')], 'nothing is skipped without a declaration')
        ->and($run(['FixtureScratch\\CreateOwnerCommand' => 'Creates the first owner before any workspace exists.', 'FixtureScratch\\InviteCommand' => 'Invites an owner before their workspace exists.']))
        ->toBe([$touch('ReportCommand')], 'the declared landlord commands pass; the undeclared one is still checked')
        ->and($run(['FixtureScratch\\CreateOwnerCommand' => '  ']))
        ->toEqualCanonicalizing(['FixtureScratch\\CreateOwnerCommand is declared a landlord command without a reason', $touch('CreateOwnerCommand'), $touch('InviteCommand'), $touch('ReportCommand')], 'an empty reason is a finding, and skips nothing')
        ->and($run(['FixtureScratch\\Ledger' => 'Not a command.', 'FixtureScratch\\GoneCommand' => 'Deleted.']))
        ->toContain('landlord command FixtureScratch\\Ledger is not a command in the source roots', 'landlord command FixtureScratch\\GoneCommand is not a command in the source roots');

    $aware = "use Illuminate\\Console\\Command;\nuse Spatie\\Multitenancy\\Commands\\Concerns\\TenantAware;\n\nfinal class InviteCommand extends Command\n{\n    use TenantAware;\n\n    public function handle(): int\n    {\n        return Ledger::query()->count();\n    }\n}\n";
    file_put_contents($base.'/src/InviteCommand.php', "<?php\n\nnamespace FixtureScratch;\n\n".$aware);
    expect($run(['FixtureScratch\\InviteCommand' => 'Invites an owner before their workspace exists.']))->toContain('FixtureScratch\\InviteCommand is declared a landlord command but is TenantAware');
});

test('landlord housekeeping is not flagged: prune on a model with no tenant scope; the rule is off without the package', function () {
    $landlord = "use Illuminate\\Database\\Eloquent\\Builder;\nuse Illuminate\\Database\\Eloquent\\Model;\nuse Illuminate\\Database\\Eloquent\\Prunable;\n\nfinal class LoginAttempt extends Model\n{\n    use Prunable;\n\n    public function prunable(): Builder\n    {\n        return static::query()->where('old', true);\n    }\n}\n";
    $base = tenantApp(['LoginAttempt.php' => $landlord, 'Ledger.php' => TENANT_MODEL]);

    expect((new Conformance(tenantConfiguration($base)))->violations('tenant-aware'))->toBe([], 'the landlord prune is not flagged (and a tenant model exists, so the rule checked something)');

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

test('a single-database app scoped by a column is detected by default: fillable, migration column, global scope or tenant trait', function () {
    $command = fn (string $model): string => "use Illuminate\\Console\\Command;\n\nfinal class Touch{$model}Command extends Command\n{\n    public function handle(): int\n    {\n        return {$model}::query()->count();\n    }\n}\n";
    $model = fn (string $name, string $body = ''): string => "use Illuminate\\Database\\Eloquent\\Model;\n\nfinal class {$name} extends Model\n{\n{$body}}\n";
    $base = tenantApp([
        'Note.php' => $model('Note', "    protected \$fillable = ['workspace_id', 'body'];\n"),
        'Invoice.php' => $model('Invoice'),
        'Slide.php' => $model('Slide', "    protected static function booted(): void\n    {\n        static::addGlobalScope('workspace', fn (\$query) => \$query->where('workspace_id', 1));\n    }\n"),
        'Deck.php' => "use Illuminate\\Database\\Eloquent\\Model;\nuse FixtureScratch\\Concerns\\BelongsToWorkspace;\n\nfinal class Deck extends Model\n{\n    use BelongsToWorkspace;\n}\n",
        'Country.php' => $model('Country'),
        'TouchNoteCommand.php' => $command('Note'),
        'TouchInvoiceCommand.php' => $command('Invoice'),
        'TouchSlideCommand.php' => $command('Slide'),
        'TouchDeckCommand.php' => $command('Deck'),
        'TouchCountryCommand.php' => $command('Country'),
    ]);
    file_put_contents($base.'/composer.json', json_encode(['require' => ['tey/kit' => '^0.4']]));
    mkdir($base.'/database/migrations', 0777, true);
    file_put_contents($base.'/database/migrations/2026_01_01_000000_create_invoices_table.php', "<?php\n\nreturn new class {\n    public function up(): void\n    {\n        Illuminate\\Support\\Facades\\Schema::create('invoices', function (\$table) {\n            \$table->id();\n            \$table->foreignId('workspace_id')->constrained();\n        });\n        Illuminate\\Support\\Facades\\Schema::create('countries', function (\$table) {\n            \$table->id();\n        });\n    }\n};\n");
    $checks = array_column((new Conformance(tenantConfiguration($base)))->violations('tenant-aware'), 'check');
    sort($checks);

    expect($checks)->toBe([
        'FixtureScratch\\TouchDeckCommand touches tenant-scoped FixtureScratch\\Deck without TenantAware',
        'FixtureScratch\\TouchInvoiceCommand touches tenant-scoped FixtureScratch\\Invoice without TenantAware',
        'FixtureScratch\\TouchNoteCommand touches tenant-scoped FixtureScratch\\Note without TenantAware',
        'FixtureScratch\\TouchSlideCommand touches tenant-scoped FixtureScratch\\Slide without TenantAware',
    ], 'fillable, migration column, global scope and tenant trait each detect; Country (no tenant key) does not');

    // An explicit tenantModels is the whole answer: only Note.
    expect(array_column((new Conformance(tenantConfiguration($base, ['tenantModels' => ['FixtureScratch\\Note']])))->violations('tenant-aware'), 'check'))
        ->toBe(['FixtureScratch\\TouchNoteCommand touches tenant-scoped FixtureScratch\\Note without TenantAware']);
});

test('kit\'s HasWorkspaces (the user\'s memberships) is not a tenant scope; BelongsToWorkspace and WorkspaceScoped are, alone or alongside it', function () {
    $command = fn (string $model): string => "use Illuminate\\Console\\Command;\n\nfinal class Touch{$model}Command extends Command\n{\n    public function handle(): int\n    {\n        return {$model}::query()->count();\n    }\n}\n";
    $base = tenantApp([
        'Concerns/HasWorkspaces.php' => "trait HasWorkspaces\n{\n}\n",
        'Concerns/BelongsToWorkspace.php' => "trait BelongsToWorkspace\n{\n}\n",
        'Concerns/WorkspaceScoped.php' => "trait WorkspaceScoped\n{\n}\n",
        'Slide.php' => "use Illuminate\\Database\\Eloquent\\Model;\nuse FixtureScratch\\Concerns\\WorkspaceScoped;\n\nfinal class Slide extends Model\n{\n    use WorkspaceScoped;\n}\n",
        'TouchSlideCommand.php' => $command('Slide'),
        'User.php' => "use Illuminate\\Foundation\\Auth\\User as Authenticatable;\nuse FixtureScratch\\Concerns\\HasWorkspaces;\n\nfinal class User extends Authenticatable\n{\n    use HasWorkspaces;\n}\n",
        'Deck.php' => "use Illuminate\\Database\\Eloquent\\Model;\nuse FixtureScratch\\Concerns\\BelongsToWorkspace;\n\nfinal class Deck extends Model\n{\n    use BelongsToWorkspace;\n}\n",
        'Member.php' => "use Illuminate\\Database\\Eloquent\\Model;\nuse FixtureScratch\\Concerns\\BelongsToWorkspace;\nuse FixtureScratch\\Concerns\\HasWorkspaces;\n\nfinal class Member extends Model\n{\n    use HasWorkspaces, BelongsToWorkspace;\n}\n",
        'TouchUserCommand.php' => $command('User'),
        'TouchDeckCommand.php' => $command('Deck'),
        'TouchMemberCommand.php' => $command('Member'),
    ]);
    file_put_contents($base.'/composer.json', json_encode(['require' => ['tey/kit' => '^0.6']]));
    $checks = array_column((new Conformance(tenantConfiguration($base)))->violations('tenant-aware'), 'check');
    sort($checks);

    expect($checks)->toBe([
        'FixtureScratch\\TouchDeckCommand touches tenant-scoped FixtureScratch\\Deck without TenantAware',
        'FixtureScratch\\TouchMemberCommand touches tenant-scoped FixtureScratch\\Member without TenantAware',
        'FixtureScratch\\TouchSlideCommand touches tenant-scoped FixtureScratch\\Slide without TenantAware',
    ], 'User (HasWorkspaces only) is not tenant-scoped; Deck and Member (BelongsToWorkspace) and Slide (WorkspaceScoped) are');
});

test('tenancy with no tenant-scoped model found is an advisory (checked nothing), never a silent pass; the gate fails it only under --strict', function () {
    $base = tenantApp(['Country.php' => "use Illuminate\\Database\\Eloquent\\Model;\n\nfinal class Country extends Model\n{\n}\n"]);
    $violations = (new Conformance(tenantConfiguration($base)))->violations('tenant-aware');

    expect($violations)->toHaveCount(1)
        ->and($violations[0])->toMatchArray(['advisory' => true, 'check' => 'tenant-aware checked nothing: no tenant-scoped model found'])
        ->and($violations[0]['hint'])->toContain('Configuration::$tenantModels');

    $gate = (new Tey\Standards\Enforcement\Gate(tenantConfiguration($base), new DateTimeImmutable('2026-10-28')))->run();
    $advisories = array_values(array_filter($gate['advisories'], fn (array $a): bool => $a['rule'] === 'tenant-aware'));

    expect(array_filter($gate['violations'], fn (array $v): bool => $v['rule'] === 'tenant-aware'))->toBe([])
        ->and($advisories)->toHaveCount(1)
        ->and($advisories[0]['check'])->toBe('tenant-aware checked nothing: no tenant-scoped model found');

    // Without any tenancy package the rule is simply off: no advisory.
    file_put_contents($base.'/composer.json', '{}');
    expect((new Conformance(tenantConfiguration($base)))->violations('tenant-aware'))->toBe([]);
});

test('standards:check passes with the checked-nothing advisory, and fails it under --strict', function () {
    $base = tenantApp(['Country.php' => "use Illuminate\\Database\\Eloquent\\Model;\n\nfinal class Country extends Model\n{\n}\n"]);
    mkdir($base.'/tests/Arch', 0777, true);
    $arguments = get_object_vars(tenantConfiguration($base));
    unset($arguments['base']);
    $export = implode('', array_map(fn (string $name, mixed $value): string => '    '.$name.': '.var_export($value, true).",\n", array_keys($arguments), $arguments));
    file_put_contents($base.'/tests/Arch/configuration.php', "<?php\n\nreturn new Tey\\Standards\\Configuration(\n    base: dirname(__DIR__, 2),\n".$export.");\n");
    $run = function (array $options) use ($base): Symfony\Component\Console\Tester\CommandTester {
        $app = new Illuminate\Foundation\Application($base);
        $app->instance('config', new Illuminate\Config\Repository(['standards' => ['base_ref' => 'origin/main']]));
        $command = new Tey\Standards\Enforcement\CheckCommand;
        $command->setLaravel($app);
        $tester = new Symfony\Component\Console\Tester\CommandTester($command);
        $tester->execute($options);

        return $tester;
    };

    $plain = $run(['--json' => true]);
    $report = json_decode($plain->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
    expect(array_column(array_filter($report['advisories'], fn (array $a): bool => $a['rule'] === 'tenant-aware'), 'check'))->toBe(['tenant-aware checked nothing: no tenant-scoped model found'])
        ->and(array_filter($report['violations'], fn (array $v): bool => $v['rule'] === 'tenant-aware'))->toBe([]);

    $strict = $run(['--json' => true, '--strict' => true]);
    expect($strict->getStatusCode())->toBe(1)->and(json_decode($strict->getDisplay(), true, flags: JSON_THROW_ON_ERROR)['exit_code'])->toBe(1);
});
