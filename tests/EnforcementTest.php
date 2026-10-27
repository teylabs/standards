<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Tey\Standards\Configuration;
use Tey\Standards\Enforcement\CheckReport;
use Tey\Standards\Enforcement\DriftReport;
use Tey\Standards\Enforcement\Gate;
use Tey\Standards\Enforcement\Hook;
use Tey\Standards\Enforcement\Installer;
use Tey\Standards\Patterns\TreeCatalog;

beforeEach(function () {
    $this->temporary = [];
    // Keep the developer's global git config (a global core.hooksPath, say) out of the installer tests.
    $this->globalGitConfig = getenv('GIT_CONFIG_GLOBAL');
    putenv('GIT_CONFIG_GLOBAL=/dev/null');
    $_SERVER['GIT_CONFIG_GLOBAL'] = $_ENV['GIT_CONFIG_GLOBAL'] = '/dev/null'; // Symfony Process reads these, not getenv()
    $this->directory = function (): string {
        $directory = sys_get_temp_dir().'/standards-enforce-'.bin2hex(random_bytes(6));
        mkdir($directory, 0777, true);
        $this->temporary[] = $directory;

        return $directory;
    };
    $this->app = function () {
        $base = ($this->directory)();
        file_put_contents($base.'/artisan', "<?php\n");
        file_put_contents($base.'/composer.json', json_encode(['name' => 'acme/app', 'require' => ['tey/standards' => '^0.11']]));

        return $base;
    };
});

afterEach(function () {
    putenv($this->globalGitConfig === false ? 'GIT_CONFIG_GLOBAL' : 'GIT_CONFIG_GLOBAL='.$this->globalGitConfig);
    unset($_SERVER['GIT_CONFIG_GLOBAL'], $_ENV['GIT_CONFIG_GLOBAL']);

    if ($this->globalGitConfig !== false) {
        $_SERVER['GIT_CONFIG_GLOBAL'] = $_ENV['GIT_CONFIG_GLOBAL'] = $this->globalGitConfig;
    }
    foreach ($this->temporary as $directory) {
        (new Filesystem)->deleteDirectory($directory);
    }
});

/**
 * @param  list<array{exit: int, output: string}>  $responses
 * @param  list<list<string>>  $calls
 */
function fakeArtisan(array $responses, array &$calls): Closure
{
    return function (array $arguments) use (&$responses, &$calls): array {
        $calls[] = $arguments;

        return array_shift($responses) ?? ['exit' => 0, 'output' => '{}'];
    };
}

function checkJson(array $violations): array
{
    return ['exit' => $violations === [] ? 0 : 1, 'output' => json_encode(['schema' => 1, 'violations' => $violations])];
}

// Layer 1: gates

test('the gate reports unexcepted violations with fix hints and passes excepted ones', function () {
    $bad = fixtureConfiguration('Bad');
    $violation = (new Tey\Standards\Conformance($bad))->violations('mail')[0];
    $configuration = fixtureConfiguration('Bad', [['rule' => 'mail', ...$violation, 'reason' => 'legacy mailer', 'date' => '2026-10-01', 'expires' => '2027-01-01']]);
    $result = (new Gate($configuration, new DateTimeImmutable('2026-10-27'), TreeCatalog::shipped()))->run();
    $mail = array_values(array_filter($result['violations'], fn (array $v): bool => $v['rule'] === 'mail'));

    expect($mail)->toBe([])
        ->and($result['allowlist']['count'])->toBe(1)
        ->and($result['violations'])->not->toBeEmpty()
        ->and(array_unique(array_column($result['violations'], 'kind')))->toBe(['violation'])
        ->and($result['skipped'])->toBe(['51']);

    foreach ($result['violations'] as $finding) {
        expect($finding['hint'])->not->toBe('');
    }
});

test('the clean fixture passes the gate', function () {
    expect((new Gate(fixtureConfiguration('Good'), new DateTimeImmutable('2026-10-27')))->run()['violations'])->toBe([]);
});

test('allowlist entries need an expiry; expired entries and stale entries are drift; near expiries are listed', function () {
    $violation = (new Tey\Standards\Conformance(fixtureConfiguration('Bad')))->violations('mail')[0];
    $entry = fn (array $extra): array => ['rule' => 'mail', ...$violation, 'reason' => 'legacy mailer', 'date' => '2026-10-01', ...$extra];
    $run = fn (array $entries, string $kind = 'Bad'): array => (new Gate(fixtureConfiguration($kind, $entries), new DateTimeImmutable('2026-10-27')))->run();

    $missing = $run([$entry([])]);
    expect(array_column(array_filter($missing['violations'], fn ($v) => $v['rule'] === 'mail'), 'kind'))->toBe(['missing-expiry']);

    $expired = $run([$entry(['expires' => '2026-10-26'])]);
    expect(array_column(array_filter($expired['violations'], fn ($v) => $v['rule'] === 'mail'), 'kind'))->toBe(['expired'])
        ->and($expired['allowlist']['expired'][0]['expires'])->toBe('2026-10-26');

    $soon = $run([$entry(['expires' => '2026-11-03'])]);
    expect(array_filter($soon['violations'], fn ($v) => $v['rule'] === 'mail'))->toBe([])
        ->and($soon['allowlist']['expiring'][0]['expires'])->toBe('2026-11-03');

    $stale = $run([['rule' => 'mail', 'target' => 'gone.php', 'check' => 'old', 'reason' => 'x', 'date' => '2026-10-01', 'expires' => '2027-01-01']], 'Good');
    expect(array_column($stale['violations'], 'kind'))->toBe(['stale']);

    expect(fn () => $run([$entry(['expires' => '2026-13-01'])]))->toThrow(InvalidArgumentException::class, 'real Y-m-d');
});

test('scoped runs keep only findings in the given files, mapping class targets through classRoots', function () {
    $gate = new Gate(fixtureConfiguration('Bad'), new DateTimeImmutable('2026-10-27'));
    $all = $gate->run()['violations'];
    $classTarget = array_values(array_filter($all, fn (array $v): bool => str_starts_with($v['target'], 'FixtureBad\\')))[0];
    $file = 'src/'.str_replace('\\', '/', substr($classTarget['target'], strlen('FixtureBad\\'))).'.php';

    expect($gate->run([])['violations'])->toBe([])
        ->and(array_column($gate->run([$file])['violations'], 'target'))->toContain($classTarget['target'])
        ->and(count($gate->run([$file])['violations']))->toBeLessThan(count($all));
});

test('a missing configuration fails closed with schema-1 JSON', function () {
    $base = ($this->app)();
    $report = CheckReport::build($base, 'tests/Arch/configuration.php', null, 'full', new DateTimeImmutable('2026-10-27'), TreeCatalog::shipped(), sha: 'abc');

    expect($report)->toMatchArray(['schema' => 1, 'app' => 'acme/app', 'sha' => 'abc', 'scope' => 'full', 'exit_code' => 1])
        ->and(array_keys($report))->toBe(['schema', 'app', 'sha', 'generated_at', 'scope', 'paths', 'violations', 'allowlist', 'skipped', 'advisories', 'gaps', 'exit_code'])
        ->and($report['violations'][0])->toMatchArray(['rule' => 'standards', 'kind' => 'error'])
        ->and($report['gaps']['catalog'])->toBeGreaterThan(0);

    expect(CheckReport::build($base, 'missing.php', [], 'changed', new DateTimeImmutable('2026-10-27'), TreeCatalog::shipped())['exit_code'])->toBe(0);
});

test('files stamped as gaps are counted', function () {
    $base = ($this->app)();
    mkdir($base.'/app');
    file_put_contents($base.'/app/Odd.php', "<?php\n// standards-pattern: gap\n");
    file_put_contents($base.'/app/Fine.php', "<?php\n// standards-pattern: mcp.read\n");

    expect(CheckReport::build($base, 'x.php', [], 'changed', new DateTimeImmutable('2026-10-27'), TreeCatalog::shipped())['gaps']['flagged'])->toBe(1);
});

test('the reusable workflow is callable and runs standards:check', function () {
    $workflow = Symfony\Component\Yaml\Yaml::parseFile(dirname(__DIR__).'/.github/workflows/standards.yml');

    expect($workflow['on'])->toHaveKey('workflow_call')
        ->and(json_encode($workflow['jobs']))->toContain('standards:check');
});

// Layer 2: hooks and installed guardrails

test('every hook is a no-op outside a Laravel app that requires tey/standards', function (string $event) {
    $calls = [];
    $plain = ($this->directory)();
    file_put_contents($plain.'/composer.json', json_encode(['name' => 'fleet/house-rules']));
    $input = ['tool_input' => ['command' => 'git commit -m x', 'file_path' => $plain.'/app/New.php', 'content' => '']];

    foreach ([$plain, ($this->directory)()] as $base) {
        expect((new Hook($base, fakeArtisan([checkJson([['rule' => 'x', 'kind' => 'violation', 'target' => 't', 'check' => 'c', 'hint' => 'h']])], $calls)))->handle($event, $input))
            ->toBe(['exit' => 0, 'stdout' => '', 'stderr' => '']);
    }

    expect($calls)->toBe([]);
})->with(Hook::EVENTS);

test('the CLI hook exits 0 silently in a repository without the package', function () {
    $plain = ($this->directory)();
    $process = new Process([PHP_BINARY, dirname(__DIR__).'/bin/standards', 'hook', 'stop'], $plain);
    $process->setInput('{}');
    $process->run();

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())->toBe('');

    $installed = new Process(['sh', '-c', Installer::command('stop')], $plain, ['CLAUDE_PROJECT_DIR' => $plain]);
    $installed->run();
    expect($installed->getExitCode())->toBe(0);
});

test('git commit is blocked on drift with fix hints; other commands and clean trees pass', function () {
    $calls = [];
    $finding = ['rule' => 'toast', 'kind' => 'violation', 'target' => 'app/X.php', 'check' => 'missing toast', 'hint' => 'Use ->toastSuccess()'];
    $hook = new Hook(($this->app)(), fakeArtisan([checkJson([$finding]), checkJson([])], $calls));

    $blocked = $hook->handle('pre-commit', ['tool_input' => ['command' => 'git add -A && git commit -m "x"']]);
    expect($blocked['exit'])->toBe(2)
        ->and($blocked['stderr'])->toContain('missing toast')->toContain('Use ->toastSuccess()');

    expect($hook->handle('pre-commit', ['tool_input' => ['command' => 'git status']])['exit'])->toBe(0);
    expect($hook->handle('pre-commit', ['tool_input' => ['command' => 'git -C . commit']])['exit'])->toBe(0);
    expect($calls)->toBe([['standards:check', '--changed', '--json'], ['standards:check', '--changed', '--json']]);
});

test('Stop blocks on drift unless a stop hook is already active', function () {
    $calls = [];
    $finding = ['rule' => '21', 'kind' => 'violation', 'target' => 'routes/web.php', 'check' => 'flat-tuple', 'hint' => 'Use Route::controller()'];
    $hook = new Hook(($this->app)(), fakeArtisan([checkJson([$finding])], $calls));

    $stop = json_decode($hook->handle('stop', [])['stdout'], true);
    expect($stop['decision'])->toBe('block')->and($stop['reason'])->toContain('flat-tuple');

    expect($hook->handle('stop', ['stop_hook_active' => true])['stdout'])->toBe('')
        ->and($calls)->toHaveCount(1);
});

test('a broken check fails the commit gate closed', function () {
    $calls = [];
    $hook = new Hook(($this->app)(), fakeArtisan([['exit' => 255, 'output' => 'PHP Fatal error']], $calls));

    expect($hook->handle('pre-commit', ['tool_input' => ['command' => 'git commit']])['exit'])->toBe(2);
});

test('a new unstamped file under app/ is soft-blocked once with the leaf, make command and next question, then allowed', function () {
    $calls = [];
    $base = ($this->app)();
    $lookup = json_encode((new Tey\Standards\Patterns\Lookup(TreeCatalog::shipped()))->find(['path' => 'resources/js/pages/settings/Alerts/Page.vue']));
    $hook = new Hook($base, fakeArtisan([['exit' => 0, 'output' => $lookup], ['exit' => 0, 'output' => $lookup]], $calls), ($this->directory)());
    $input = ['session_id' => 's1', 'tool_input' => ['file_path' => $base.'/resources/js/pages/settings/Alerts/Page.vue', 'content' => '<template />']];

    $first = json_decode($hook->handle('pre-write', $input)['stdout'], true)['hookSpecificOutput'];
    expect($first['permissionDecision'])->toBe('deny')
        ->and($first['permissionDecisionReason'])->toContain('settings.toggles')
        ->toContain('php artisan standards:make settings-section --flavor=toggles')
        ->toContain('Does the settings group need its own nav section')
        ->toContain('call bakery.find (offline: `php artisan standards:pattern`)')
        ->toContain('standards-pattern: gap');

    $second = json_decode($hook->handle('pre-write', $input)['stdout'], true)['hookSpecificOutput'];
    expect($second)->not->toHaveKey('permissionDecision')
        ->and($second['additionalContext'])->toContain('settings.form');
    expect($calls)->toBe([['standards:pattern', 'resources/js/pages/settings/Alerts/Page.vue', '--json'], ['standards:pattern', 'resources/js/pages/settings/Alerts/Page.vue', '--json']]);
});

test('stamped, existing and out-of-scope files are never blocked', function () {
    $calls = [];
    $base = ($this->app)();
    mkdir($base.'/app');
    file_put_contents($base.'/app/Existing.php', '<?php');
    $hook = new Hook($base, fakeArtisan([], $calls), ($this->directory)());

    $stamped = $hook->handle('pre-write', ['tool_input' => ['file_path' => 'app/Mcp/Tools/ReadTool.php', 'content' => "<?php\n// standards-pattern: mcp.read\n"]]);
    expect(json_decode($stamped['stdout'], true)['hookSpecificOutput'])->not->toHaveKey('permissionDecision');

    expect($hook->handle('pre-write', ['tool_input' => ['file_path' => $base.'/app/Existing.php', 'content' => '']])['stdout'])->toBe('')
        ->and($hook->handle('pre-write', ['tool_input' => ['file_path' => $base.'/docs/notes.md', 'content' => '']])['stdout'])->toBe('')
        ->and($hook->handle('pre-write', ['tool_input' => ['file_path' => '/elsewhere/app/X.php', 'content' => '']])['stdout'])->toBe('');
});

test('after an edit the file is checked and findings come back as context, never a block', function () {
    $calls = [];
    $base = ($this->app)();
    $finding = ['rule' => 'js-imports', 'kind' => 'violation', 'target' => 'resources/js/pages/A/Page.vue', 'check' => 'left-pad', 'hint' => 'Declare it'];
    $hook = new Hook($base, fakeArtisan([checkJson([$finding]), checkJson([])], $calls));

    $result = $hook->handle('post-edit', ['tool_input' => ['file_path' => $base.'/resources/js/pages/A/Page.vue']]);
    expect($result['exit'])->toBe(0)
        ->and(json_decode($result['stdout'], true)['hookSpecificOutput']['additionalContext'])->toContain('left-pad');
    expect($hook->handle('post-edit', ['tool_input' => ['file_path' => $base.'/app/B.php']])['stdout'])->toBe('');
    expect($calls)->toBe([['standards:check', '--file=resources/js/pages/A/Page.vue', '--json'], ['standards:check', '--file=app/B.php', '--json']]);
});

test('install merges hooks into existing settings without clobbering, and re-running is idempotent', function () {
    $base = ($this->app)();
    mkdir($base.'/.claude');
    $existing = ['permissions' => ['allow' => ['Bash(ls)']], 'hooks' => ['PreToolUse' => [['matcher' => 'Bash', 'hooks' => [['type' => 'command', 'command' => 'echo mine']]]]]];
    file_put_contents($base.'/.claude/settings.json', json_encode($existing));
    $installer = new Installer($base, TreeCatalog::shipped());

    $installer->settings();
    $once = file_get_contents($base.'/.claude/settings.json');
    $installer->settings();
    $settings = json_decode(file_get_contents($base.'/.claude/settings.json'), true);

    expect(file_get_contents($base.'/.claude/settings.json'))->toBe($once)
        ->and($settings['permissions'])->toBe(['allow' => ['Bash(ls)']])
        ->and($settings['hooks']['PreToolUse'][0]['hooks'][0]['command'])->toBe('echo mine')
        ->and(array_column($settings['hooks']['PreToolUse'], 'matcher'))->toBe(['Bash', 'Bash', 'Write'])
        ->and($settings['hooks']['Stop'][0]['hooks'][0]['command'])->toBe(Installer::command('stop'))
        ->and($settings['hooks']['PostToolUse'][0]['matcher'])->toBe('Edit|MultiEdit|Write');

    file_put_contents($base.'/.claude/settings.json', 'not json');
    expect(fn () => $installer->settings())->toThrow(InvalidArgumentException::class, 'refusing to overwrite');
    expect(file_get_contents($base.'/.claude/settings.json'))->toBe('not json');
});

test('install writes the AGENTS.md pattern index between markers and keeps the rest', function () {
    $base = ($this->app)();
    file_put_contents($base.'/AGENTS.md', "# App\n\nHouse notes.\n");
    $installer = new Installer($base, TreeCatalog::shipped());
    $installer->agents();
    $installer->agents();
    $agents = file_get_contents($base.'/AGENTS.md');

    expect($agents)->toStartWith("# App\n\nHouse notes.\n")
        ->and(substr_count($agents, Installer::START))->toBe(1)
        ->and($agents)->toContain('Traverse before you build')
        ->toContain('`settings.toggles` | ready')
        ->toContain('php artisan standards:make page --flavor=index <Name>');

    file_put_contents($base.'/AGENTS.md', Installer::START."\nbroken");
    expect(fn () => $installer->agents())->toThrow(InvalidArgumentException::class, 'unmatched');
});

test('install writes one Boost skill per non-gap recipe, path-scoped for Claude', function () {
    $base = ($this->app)();
    (new Installer($base, TreeCatalog::shipped()))->skills();
    $skill = file_get_contents($base.'/.claude/skills/standards-settings-form/SKILL.md');

    expect($skill)->toContain("name: standards-settings-form\n")
        ->toContain("paths:\n  - \"app/**/Http/Controllers/Settings/**\"")
        ->toContain('php artisan standards:make settings-section --flavor=form <Name>')
        ->and(file_get_contents($base.'/.ai/skills/standards-settings-form/SKILL.md'))->toBe($skill)
        ->and(is_dir($base.'/.claude/skills/standards-mcp-queued'))->toBeFalse();
});

test('install adds a pre-push hook but never replaces someone else\'s', function () {
    $base = ($this->app)();
    (new Process(['git', 'init', '-q'], $base))->mustRun();
    $installer = new Installer($base, TreeCatalog::shipped());

    expect($installer->prePush())->toBe('pre-push hook installed')
        ->and(file_get_contents($base.'/.git/hooks/pre-push'))->toContain('standards:check --changed')
        ->and($installer->prePush())->toBe('pre-push hook installed');

    file_put_contents($base.'/.git/hooks/pre-push', "#!/bin/sh\necho theirs\n");
    expect($installer->prePush())->toContain('left alone')
        ->and(file_get_contents($base.'/.git/hooks/pre-push'))->toBe("#!/bin/sh\necho theirs\n");
});

test('install writes the pre-push hook into an in-repo core.hooksPath', function () {
    $base = ($this->app)();
    (new Process(['git', 'init', '-q'], $base))->mustRun();
    (new Process(['git', 'config', 'core.hooksPath', '.githooks'], $base))->mustRun();

    expect((new Installer($base, TreeCatalog::shipped()))->prePush())->toBe('pre-push hook installed')
        ->and(file_get_contents($base.'/.githooks/pre-push'))->toContain('standards:check --changed')
        ->and(is_file($base.'/.git/hooks/pre-push'))->toBeFalse();
});

test('install warns and prints the line when core.hooksPath is outside the repository', function () {
    $base = ($this->app)();
    $outside = ($this->directory)();
    (new Process(['git', 'init', '-q'], $base))->mustRun();
    (new Process(['git', 'config', 'core.hooksPath', $outside], $base))->mustRun();

    expect((new Installer($base, TreeCatalog::shipped()))->prePush())->toContain('core.hooksPath points outside this repository')
        ->toContain('php artisan standards:check --changed')
        ->and(is_file($outside.'/pre-push'))->toBeFalse()
        ->and(is_file($base.'/.git/hooks/pre-push'))->toBeFalse();
});

test('install fills in missing Boost agents and packages and never changes ones that are set', function () {
    $base = ($this->app)();
    file_put_contents($base.'/composer.json', json_encode(['require' => ['tey/standards' => '^0.11', 'laravel/fortify' => '^1.0'], 'require-dev' => ['laravel/boost' => '^2.10', 'pestphp/pest' => '^5.0']]));
    mkdir($base.'/vendor/laravel/fortify/resources/boost', 0777, true);
    mkdir($base.'/vendor/pestphp/pest', 0777, true);
    $installer = new Installer($base, TreeCatalog::shipped());

    expect($installer->boost())->toContain('added agents (claude_code) and packages (laravel/fortify)')
        ->and(json_decode(file_get_contents($base.'/boost.json'), true))->toBe(['agents' => ['claude_code'], 'packages' => ['laravel/fortify']]);

    file_put_contents($base.'/boost.json', json_encode(['agents' => ['cursor'], 'packages' => [], 'guidelines' => true]));
    expect($installer->boost())->toBe('boost.json kept: agents and packages are set')
        ->and(json_decode(file_get_contents($base.'/boost.json'), true))->toBe(['agents' => ['cursor'], 'packages' => [], 'guidelines' => true]);

    file_put_contents($base.'/boost.json', json_encode(['agents' => ['cursor'], 'mcp' => true]));
    expect($installer->boost())->toContain('added packages')
        ->and(json_decode(file_get_contents($base.'/boost.json'), true))->toBe(['agents' => ['cursor'], 'mcp' => true, 'packages' => ['laravel/fortify']]);
});

test('install leaves boost.json alone without laravel/boost', function () {
    $base = ($this->app)();
    expect((new Installer($base, TreeCatalog::shipped()))->boost())->toContain('skipped')
        ->and(is_file($base.'/boost.json'))->toBeFalse();
});

// Layer 4: drift report

test('the drift report aggregates apps and lists regressions against the previous run', function () {
    $report = fn (string $app, array $violations): array => ['schema' => 1, 'app' => $app, 'sha' => 'abc', 'violations' => $violations, 'allowlist' => ['count' => 2, 'expiring' => [], 'expired' => [['rule' => 'x']]], 'gaps' => ['catalog' => 9, 'flagged' => 1, 'demand' => ['total' => 3, 'by_unit_type' => ['mcp' => 3]]]];
    $a = ['rule' => 'toast', 'kind' => 'violation', 'target' => 'A.php', 'check' => 'c', 'hint' => ''];
    $b = ['rule' => '21', 'kind' => 'violation', 'target' => 'B.php', 'check' => 'c', 'hint' => ''];

    $first = DriftReport::aggregate([$report('one', [$a]), $report('two', [])], generatedAt: '2026-10-26T00:00:00Z');
    $second = DriftReport::aggregate([$report('one', [$b]), $report('two', [])], $first, '2026-10-27T00:00:00Z');

    expect($first['apps'][0]['first_run'])->toBeTrue()
        ->and($second['apps'][0]['regressions'])->toBe(['21 | violation | B.php | c'])
        ->and($second['apps'][0]['fixed'])->toBe(['toast | violation | A.php | c'])
        ->and($second['totals'])->toMatchArray(['apps' => 2, 'violations' => 1, 'regressions' => 1, 'fixed' => 1, 'expired' => 2, 'flagged_gaps' => 2, 'gap_demand' => 6])
        ->and(DriftReport::text($second))->toContain('+ 21 | violation | B.php | c');

    expect(fn () => DriftReport::aggregate([['schema' => 2]]))->toThrow(InvalidArgumentException::class);
});

test('the report CLI reads report files and fails --strict on regressions', function () {
    $directory = ($this->directory)();
    $violation = ['rule' => 'toast', 'kind' => 'violation', 'target' => 'A.php', 'check' => 'c', 'hint' => ''];
    file_put_contents($directory.'/previous.json', json_encode(DriftReport::aggregate([['schema' => 1, 'app' => 'one', 'violations' => []]])));
    mkdir($directory.'/reports');
    file_put_contents($directory.'/reports/one.json', json_encode(['schema' => 1, 'app' => 'one', 'sha' => 'abc', 'violations' => [$violation]]));
    $run = function (array $arguments) use ($directory): Process {
        $process = new Process([PHP_BINARY, dirname(__DIR__).'/bin/standards', 'report', ...$arguments], $directory);
        $process->run();

        return $process;
    };

    $strict = $run(['reports', '--previous=previous.json', '--strict']);
    expect($strict->getExitCode())->toBe(1)->and($strict->getOutput())->toContain('1 regressions');

    $json = $run(['reports/one.json', '--json']);
    expect($json->getExitCode())->toBe(0)
        ->and(json_decode($json->getOutput(), true)['totals']['violations'])->toBe(1);

    expect($run(['missing'])->getExitCode())->toBe(2);
});

test('the artisan commands are registered and print the lookup, gaps and a fail-closed check', function () {
    $base = ($this->app)();
    $container = new class($base) extends Illuminate\Container\Container
    {
        public function __construct(private string $root) {}

        public function basePath(string $path = ''): string
        {
            return $this->root.($path === '' ? '' : '/'.$path);
        }

        public function configPath(string $path = ''): string
        {
            return $this->basePath('config'.($path === '' ? '' : '/'.$path));
        }

        public function runningInConsole(): bool
        {
            return true;
        }

        public function runningUnitTests(): bool
        {
            return true;
        }
    };
    $container->instance('config', $config = new Illuminate\Config\Repository);
    $container->instance(Illuminate\Contracts\Config\Repository::class, $config);
    $previous = Illuminate\Container\Container::getInstance();
    Illuminate\Container\Container::setInstance($container);

    try {
        $provider = new Tey\Standards\StandardsServiceProvider($container);
        $provider->register();
        $provider->boot();
        $artisan = new Illuminate\Console\Application($container, new Illuminate\Events\Dispatcher($container), 'test');
        $artisan->resolveCommands([Tey\Standards\Enforcement\PatternCommand::class, Tey\Standards\Enforcement\CheckCommand::class, Tey\Standards\Enforcement\MakeCommand::class, Tey\Standards\Enforcement\InstallCommand::class]);

        expect($artisan->call('standards:pattern'))->toBe(0)
            ->and($artisan->output())->toContain('? What are you building?')->toContain('8. A connection to another service → integrations');
        expect($artisan->call('standards:pattern', ['--answer' => ['3', '1', '2']]))->toBe(0)
            ->and($artisan->output())->toContain('■ mcp.write [ready] mcp-parity')->toContain('make (tey/standards): php artisan standards:make capability-tool --flavor=write <Name>')
            ->toContain('with the Bakery installed: php artisan mod:butter/mcp-parity <Name>');
        expect($artisan->call('standards:pattern', ['query' => 'manage records with bulk actions']))->toBe(0)
            ->and($artisan->output())->toContain('Plan: manage-records')->toContain('bulk actions (when bulk=yes): ui-page.data-table [ready]');
        expect($artisan->call('standards:pattern', ['--recipe' => 'ui-page.index']))->toBe(0)
            ->and($artisan->output())->toContain('- DataTable (rows)');
        expect($artisan->call('standards:pattern', ['--gaps' => true]))->toBe(0)
            ->and($artisan->output())->toContain('- data-actions.tenant-model (fleet 4 apps × 11, past 17 apps × 190)')->not->toContain('- ui-page.crud-resource (');
        expect(is_file($base.'/'.Tey\Standards\Enforcement\GapDemand::FILE))->toBeFalse();
        expect($artisan->call('standards:pattern', ['--answer' => ['3', 'None of these']]))->toBe(0)
            ->and($artisan->output())->toContain('No recipe fits. Proceed');
        expect(Tey\Standards\Enforcement\GapDemand::counts($base))->toBe(['total' => 1, 'by_unit_type' => ['mcp' => 1]])
            ->and(file_get_contents($base.'/'.Tey\Standards\Enforcement\GapDemand::FILE))->not->toContain($base);
        expect($artisan->call('standards:pattern', ['--answer' => ['3', '9']]))->toBe(2);
        expect($artisan->call('standards:check', ['--json' => true]))->toBe(1);
        $check = json_decode($artisan->output(), true);
        expect($check['violations'][0]['kind'])->toBe('error')
            ->and($check['gaps']['demand'])->toBe(['total' => 1, 'by_unit_type' => ['mcp' => 1]]);
        expect($artisan->call('standards:make', ['unit' => 'action', 'name' => 'Archive']))->toBe(0)
            ->and(file_get_contents($base.'/app/Actions/Archive.php'))->toContain('standards-pattern: data-actions.action');
        expect($artisan->call('standards:make', ['unit' => 'command', 'name' => 'PruneVisits', '--maintenance' => true]))->toBe(0)
            ->and(file_get_contents($base.'/app/Console/Commands/PruneVisitsCommand.php'))->toContain('standards-pattern: background.command')->toContain('{--days=30')
            ->and(is_file($base.'/tests/Feature/Console/PruneVisitsCommandTest.php'))->toBeTrue();
        expect($artisan->call('standards:make', ['unit' => 'command', 'name' => 'Both', '--report' => true, '--backfill' => true]))->toBe(2)
            ->and($artisan->call('standards:make', ['unit' => 'action', 'name' => 'Flagged', '--report' => true]))->toBe(2);
        expect($config->get('boost.mcp.tools.include'))->toBeNull();
    } finally {
        Illuminate\Container\Container::setInstance($previous);
    }
});

test('install scaffolds an inferred configuration and conformance test when missing, so check can run', function () {
    $base = fixtureApp();

    try {
        unlink($base.'/tests/Arch/configuration.php');
        $installer = new Installer($base, TreeCatalog::shipped());
        $lines = $installer->configuration();

        expect($lines[0])->toStartWith('tests/Arch/configuration.php scaffolded; inferred: class roots app => App; Inertia pages resources/js/pages; typed ViewModel base App\UI\ViewModel; tey/kit absent; tey/mod absent')
            ->and($lines[1])->toBe('tests/Arch/ConformanceTest.php scaffolded')
            ->and(file_get_contents($base.'/tests/Arch/configuration.php'))->toContain("classRoots: ['app' => 'App'],")->toContain('base: dirname(__DIR__, 2),')
            ->and(file_get_contents($base.'/tests/Arch/ConformanceTest.php'))->toContain("require __DIR__.'/configuration.php'");

        $report = CheckReport::build($base, 'tests/Arch/configuration.php', null, 'full', new DateTimeImmutable('2026-10-27'), TreeCatalog::shipped());
        expect(array_filter($report['violations'], fn (array $v): bool => $v['kind'] === 'error'))->toBe([]);

        file_put_contents($base.'/tests/Arch/configuration.php', '<?php // mine');
        expect($installer->configuration())->toBe(['tests/Arch/configuration.php kept', 'Arch conformance test kept'])
            ->and(file_get_contents($base.'/tests/Arch/configuration.php'))->toBe('<?php // mine');
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }
});

test('install in a bare app with no ViewModel base infers none, so rule 20 runs and checks ViewModels by suffix', function () {
    $base = ($this->directory)();
    file_put_contents($base.'/artisan', "<?php\n");
    file_put_contents($base.'/composer.json', json_encode(['name' => 'acme/bare', 'require' => ['tey/standards' => '*'], 'autoload' => ['psr-4' => ['BareApp\\' => 'app/']]]));
    mkdir($base.'/app/ViewModels', 0777, true);
    mkdir($base.'/app/Reports', 0777, true);
    file_put_contents($base.'/app/ViewModels/StatsViewModel.php', "<?php\n\nnamespace BareApp\\ViewModels;\n\nfinal class StatsViewModel {}\n");
    file_put_contents($base.'/app/Reports/TotalsViewModel.php', "<?php\n\nnamespace BareApp\\Reports;\n\nfinal class TotalsViewModel {}\n");
    $loader = new Composer\Autoload\ClassLoader;
    $loader->addPsr4('BareApp\\', $base.'/app');
    $loader->register();

    try {
        $lines = (new Installer($base, TreeCatalog::shipped()))->configuration();
        expect($lines[0])->toContain('typed ViewModel base none found')
            ->and(file_get_contents($base.'/tests/Arch/configuration.php'))->toContain("viewModelBases: [],")->toContain("typedViewModel: '',");

        $report = CheckReport::build($base, 'tests/Arch/configuration.php', null, 'full', new DateTimeImmutable('2026-10-27'), TreeCatalog::shipped());
        $rule20 = array_values(array_filter($report['violations'], fn (array $v): bool => $v['rule'] === '20'));
        expect(array_column($rule20, 'kind'))->not->toContain('error')
            ->and(array_column($rule20, 'check'))->toBe(['ViewModel outside configured roots'])
            ->and($rule20[0]['target'])->toBe('BareApp\Reports\TotalsViewModel');
    } finally {
        $loader->unregister();
    }
});

test('install keeps an existing conformance test elsewhere and infers kit and mod', function () {
    $base = ($this->app)();
    mkdir($base.'/app/Http/Controllers', 0777, true);
    mkdir($base.'/tests/Architecture', 0777, true);
    file_put_contents($base.'/tests/Architecture/RulesTest.php', '<?php foreach (Tey\Standards\Conformance::TESTS as $rule => $name) {}');
    file_put_contents($base.'/composer.json', json_encode(['require' => ['tey/standards' => '*', 'tey/kit' => '*', 'tey/mod' => '*'], 'autoload' => ['psr-4' => ['App\\' => 'app/']]]));
    $lines = (new Installer($base, TreeCatalog::shipped()))->configuration();

    expect($lines[0])->toContain('typed ViewModel base Tey\Kit\Features\Core\ViewModels\ViewModel')->toContain('tey/kit present')->toContain('tey/mod present')->toContain('Inertia pages not found')
        ->and($lines[1])->toBe('Arch conformance test kept')
        ->and(is_file($base.'/tests/Arch/ConformanceTest.php'))->toBeFalse();
});

test('with tey/mod the scaffold infers module controller roots from the layout and from where controllers live', function () {
    $base = fixtureApp();

    try {
        unlink($base.'/tests/Arch/configuration.php');
        $composer = json_decode(file_get_contents($base.'/composer.json'), true);
        $composer['require']['tey/mod'] = '^0.11';
        file_put_contents($base.'/composer.json', json_encode($composer));
        mkdir($base.'/config');
        file_put_contents($base.'/config/mod.php', "<?php\n\nreturn ['layout' => 'modules'];\n");
        $controllers = [
            'Billing/Http/Controllers/InvoiceController' => 'App\Modules\Billing\Http\Controllers',
            'Shipping/Http/Controllers/ParcelController' => 'App\Modules\Shipping\Http\Controllers',
            'Billing/Http/WebhookController' => 'App\Modules\Billing\Http',
        ];

        foreach ($controllers as $path => $namespace) {
            is_dir(dirname($base.'/app/Modules/'.$path)) || mkdir(dirname($base.'/app/Modules/'.$path), 0777, true);
            file_put_contents($base.'/app/Modules/'.$path.'.php', "<?php\n\nnamespace {$namespace};\n\nfinal class ".basename($path)."\n{\n    public function index(): string\n    {\n        return '';\n    }\n}\n");
        }

        $lines = (new Installer($base, TreeCatalog::shipped()))->configuration();
        $configuration = require $base.'/tests/Arch/configuration.php';

        expect($lines[0])->toContain('tey/mod present, layout modules, module controllers app/Modules/*/Http/Controllers*, app/Modules/*/Http')
            ->and($configuration->controllerRoots)->toContain('app/Http/Controllers*', 'app/Modules/*/Http/Controllers*', 'app/Modules/*/Http');

        $misplaced = fn (Configuration $config): array => array_values(array_filter((new Tey\Standards\Conformance($config))->violations('20'), fn (array $v): bool => $v['check'] === 'controller outside configured Http controllers'));

        expect($misplaced($configuration))->toBe([])
            ->and(array_column($misplaced(new Configuration(...array_replace(get_object_vars($configuration), ['controllerRoots' => ['app/Http/Controllers*']]))), 'target'))
            ->toEqualCanonicalizing(['App\Modules\Billing\Http\Controllers\InvoiceController', 'App\Modules\Shipping\Http\Controllers\ParcelController', 'App\Modules\Billing\Http\WebhookController']);
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }
});
