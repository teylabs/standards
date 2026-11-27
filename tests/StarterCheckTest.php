<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Tey\Standards\Preflight\StarterCheck;
use Tey\Standards\Preflight\Status;

/** A scratch tey/kit app with the starter's good defaults; each case overrides one file or config key. */
function starterApp(array $files = [], array $config = [], array $packages = ['tey/kit', 'laravel/telescope', 'laravel/nightwatch']): array
{
    $base = sys_get_temp_dir().'/starter-check-'.bin2hex(random_bytes(4));
    $good = [
        'composer.json' => json_encode(['require' => array_fill_keys($packages, '*')]),
        'docs/deploy.md' => "Run `php artisan queue:work --timeout=85 --tries=3 --backoff=10,60,300 --max-jobs=500 --max-time=3600`.\n",
        'routes/console.php' => "<?php\nSchedule::command('queue:prune-failed --hours=168')->daily();\nSchedule::command('telescope:prune --hours=48')->daily();\n",
        'app/Providers/AppServiceProvider.php' => "<?php\nModel::shouldBeStrict(! \$this->app->isProduction());\nNightwatch::rejectQueries(fn (\$q) => preg_match('/\\b(cache|cache_locks)\\b/', \$q->sql));\n",
        'app/Providers/TelescopeServiceProvider.php' => "<?php\nclass TelescopeServiceProvider {\n    public function register(): void\n    {\n        Telescope::filter(fn (\$e) => config('telescope.log_everything') || \$e->isReportableException() || \$e->isFailedRequest() || \$e->isFailedJob() || \$e->isScheduledTask() || \$e->isSlowQuery() || \$e->hasMonitoredTag());\n    }\n\n    protected function gate(): void\n    {\n        Gate::define('viewTelescope', fn (\$user): bool => Operators::allows(\$user));\n    }\n}\n",
        'app/Jobs/SendDigest.php' => "<?php\nclass SendDigest implements ShouldQueue\n{\n    public \$timeout = 60;\n    public \$tries = 3;\n}\n",
        'database/migrations/2026_01_01_create_notes.php' => "<?php\nSchema::create('notes', function (Blueprint \$table) {\n    \$table->foreignId('workspace_id')->index()->constrained();\n});\n",
    ];
    foreach ([...$good, ...$files] as $path => $body) {
        if ($body === null) {
            continue;
        }
        is_dir(dirname($base.'/'.$path)) || mkdir(dirname($base.'/'.$path), 0777, true);
        file_put_contents($base.'/'.$path, $body);
    }
    $repo = new Repository(array_replace_recursive([
        'app' => ['env' => 'production'],
        'queue' => ['default' => 'database', 'connections' => ['database' => ['driver' => 'database', 'retry_after' => 180], 'sync' => ['driver' => 'sync'], 'cloud' => ['driver' => 'cloud']]],
        'cache' => ['default' => 'redis', 'stores' => ['redis' => ['driver' => 'redis'], 'database' => ['driver' => 'database']]],
        'nightwatch' => ['filtering' => ['ignore_cache_events' => true]],
        'kit' => ['tenancy' => ['rls' => ['enabled' => false, 'except' => []]]],
    ], $config));

    return [$base, $repo];
}

function starterRun(string $check, array $files = [], array $config = [], array $packages = ['tey/kit', 'laravel/telescope', 'laravel/nightwatch']): array
{
    [$base, $repo] = starterApp($files, $config, $packages);
    $result = (new StarterCheck($check, $repo, $base))->run();

    return [$result->status, $result->message];
}

test('the starter defaults pass all three checks', function (string $check) {
    expect(starterRun($check)[0])->toBe(Status::Pass);
})->with(StarterCheck::NAMES);

test('an app without tey/kit is not applicable', function (string $check) {
    expect(starterRun($check, packages: [])[1])->toContain('Not applicable');
})->with(StarterCheck::NAMES);

test('queue.config fails each bad combination and names the fix', function (array $files, array $config, Status $status, string $says) {
    [$got, $message] = starterRun('queue.config', $files, $config);
    expect($got)->toBe($status)->and($message)->toContain($says);
})->with([
    'timeout at retry_after' => [[], ['queue' => ['connections' => ['database' => ['retry_after' => 85]]]], Status::Fail, 'at or above retry_after'],
    'timeout above Cloud visibility' => [[], ['queue' => ['default' => 'cloud'], 'preflight' => ['queue' => ['visibility_timeout' => 60]]], Status::Fail, 'visibility timeout'],
    'timeout above 85 on Flex' => [['docs/deploy.md' => "`php artisan queue:work --timeout=120 --tries=3`\n"], ['queue' => ['default' => 'cloud']], Status::Fail, 'Flex'],
    'job timeout above the worker' => [['app/Jobs/SendDigest.php' => "<?php\nclass SendDigest implements ShouldQueue { public \$timeout = 300; }\n"], [], Status::Fail, 'SendDigest::$timeout (300)'],
    'job tries above the worker' => [['app/Jobs/SendDigest.php' => "<?php\nclass SendDigest implements ShouldQueue { public \$tries = 5; }\n"], [], Status::Fail, 'SendDigest::$tries (5)'],
    'no worker command' => [['docs/deploy.md' => "Deploy.\n"], [], Status::Fail, 'No `queue:work` command'],
    'prune-failed not scheduled' => [['routes/console.php' => "<?php\nSchedule::command('telescope:prune')->daily();\n"], [], Status::Fail, 'queue:prune-failed is not scheduled'],
    'sync in production with a queued job' => [[], ['queue' => ['default' => 'sync']], Status::Warn, 'QUEUE_CONNECTION=sync in production'],
    'sync with an inline job' => [['app/Jobs/SendDigest.php' => "<?php\n/** @runs-inline */\nclass SendDigest implements ShouldQueue {}\n"], ['queue' => ['default' => 'sync']], Status::Pass, 'agree'],
]);

test('perf.defaults fails each missing default and names the fix', function (array $files, array $config, Status $status, string $says) {
    [$got, $message] = starterRun('perf.defaults', $files, $config);
    expect($got)->toBe($status)->and($message)->toContain($says);
})->with([
    'no strict models' => [['app/Providers/AppServiceProvider.php' => "<?php\nNightwatch::rejectQueries(fn (\$q) => preg_match('/\\b(cache|cache_locks)\\b/', \$q->sql));\n"], [], Status::Fail, 'Model::preventLazyLoading(! $this->app->isProduction())'],
    'preventLazyLoading alone passes' => [['app/Providers/AppServiceProvider.php' => "<?php\nModel::preventLazyLoading(! \$this->app->isProduction());\nNightwatch::rejectQueries(fn (\$q) => preg_match('/\\b(cache|cache_locks)\\b/', \$q->sql));\n"], [], Status::Pass, 'Strict models'],
    'database cache in production' => [[], ['cache' => ['default' => 'database']], Status::Fail, 'is the database'],
    'database cache on the iterating profile' => [[], ['cache' => ['default' => 'database'], 'app' => ['profile' => 'iterating']], Status::Warn, 'iterating profile'],
    'Nightwatch records cache events' => [[], ['nightwatch' => ['filtering' => ['ignore_cache_events' => false]]], Status::Fail, 'NIGHTWATCH_IGNORE_CACHE_EVENTS'],
    'Nightwatch filtering key absent' => [[], ['nightwatch' => ['filtering' => ['ignore_cache_events' => null]]], Status::Fail, 'NIGHTWATCH_IGNORE_CACHE_EVENTS'],
    'Nightwatch keeps cache_locks queries' => [['app/Providers/AppServiceProvider.php' => "<?php\nModel::shouldBeStrict();\nNightwatch::rejectQueries(fn (\$q) => str_contains(\$q->sql, ' cache '));\n"], [], Status::Fail, 'cache_locks'],
    'unindexed foreignId' => [['database/migrations/2026_01_01_create_notes.php' => "<?php\nSchema::create('notes', function (Blueprint \$table) {\n    \$table->foreignId('workspace_id')->constrained();\n});\n"], [], Status::Fail, 'notes.workspace_id'],
    'unindexed foreign()' => [['database/migrations/2026_01_01_create_notes.php' => "<?php\nSchema::create('notes', function (Blueprint \$table) {\n    \$table->unsignedBigInteger('owner_id');\n    \$table->foreign('owner_id')->references('id')->on('users');\n});\n"], [], Status::Fail, 'notes.owner_id'],
    'heavy page in the query report' => [['storage/app/kit-query-report.json' => json_encode(['pages' => [['uri' => '/dashboard', 'queries' => 60], ['uri' => '/notes', 'queries' => 12]]])], [], Status::Warn, '/dashboard (60)'],
]);

test('telescope.config fails an open or email gate, a missing filter, prune or RLS exception', function (array $files, array $config, Status $status, string $says, array $packages = ['tey/kit', 'laravel/telescope', 'laravel/nightwatch']) {
    [$got, $message] = starterRun('telescope.config', $files, $config, $packages);
    expect($got)->toBe($status)->and($message)->toContain($says);
})->with([
    'not installed' => [[], [], Status::Fail, 'laravel/telescope is not installed', ['tey/kit']],
    'gate returns true' => [['app/Providers/TelescopeServiceProvider.php' => "<?php\nTelescope::filter(fn () => config('telescope.log_everything') || isReportableException() || isFailedRequest() || isFailedJob() || isScheduledTask() || isSlowQuery() || hasMonitoredTag());\n    protected function gate(): void\n    {\n        Gate::define('viewTelescope', fn (\$user): bool => true);\n    }\n"], [], Status::Fail, 'gate is open'],
    'environment-only gate' => [['app/Providers/TelescopeServiceProvider.php' => "<?php\n    protected function gate(): void\n    {\n        Gate::define('viewTelescope', fn (\$user): bool => app()->isLocal());\n    }\n"], [], Status::Fail, 'gate is open'],
    'hard-coded emails' => [['app/Providers/TelescopeServiceProvider.php' => "<?php\nTelescope::filter(fn () => config('telescope.log_everything') || isReportableException() || isFailedRequest() || isFailedJob() || isScheduledTask() || isSlowQuery() || hasMonitoredTag());\n    protected function gate(): void\n    {\n        Gate::define('viewTelescope', fn (\$user): bool => in_array(\$user->email, ['ops@example.com']));\n    }\n"], [], Status::Fail, 'names email addresses'],
    'emails in config' => [['config/telescope.php' => "<?php\nreturn ['operators' => ['ops@example.com']];\n"], [], Status::Fail, 'config/telescope.php names email addresses'],
    'permission through a policy passes' => [['app/Providers/TelescopeServiceProvider.php' => "<?php\nTelescope::filter(fn () => config('telescope.log_everything') || isReportableException() || isFailedRequest() || isFailedJob() || isScheduledTask() || isSlowQuery() || hasMonitoredTag());\n    protected function gate(): void\n    {\n        Gate::define('viewTelescope', fn (\$user): bool => \$user->can('telescope.view'));\n    }\n"], [], Status::Pass, 'gated'],
    'filter not rule 72' => [['app/Providers/TelescopeServiceProvider.php' => "<?php\nTelescope::filter(fn () => true);\n    protected function gate(): void\n    {\n        Gate::define('viewTelescope', fn (\$user): bool => Operators::allows(\$user));\n    }\n"], [], Status::Fail, 'rule 72'],
    'prune not daily' => [['routes/console.php' => "<?php\nSchedule::command('queue:prune-failed')->daily();\nSchedule::command('telescope:prune')->weekly();\n"], [], Status::Fail, 'telescope:prune is not scheduled daily'],
    'RLS without telescope tables' => [[], ['kit' => ['tenancy' => ['rls' => ['enabled' => true, 'except' => ['roles']]]]], Status::Fail, 'telescope_entries'],
    'RLS with telescope tables' => [[], ['kit' => ['tenancy' => ['rls' => ['enabled' => true, 'except' => ['telescope_entries', 'telescope_entries_tags', 'telescope_monitoring']]]]], Status::Pass, 'gated'],
]);
