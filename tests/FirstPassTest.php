<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Tey\Standards\Telemetry\Context;
use Tey\Standards\Telemetry\Episodes;
use Tey\Standards\Telemetry\FirstPassCommand;
use Tey\Standards\Telemetry\Recompute;
use Tey\Standards\Telemetry\Recorder;
use Tey\Standards\Telemetry\Report;
use Tey\Standards\Telemetry\RunLog;

beforeEach(function () {
    $this->fpBase = sys_get_temp_dir().'/standards-first-pass-'.bin2hex(random_bytes(6));
    mkdir($this->fpBase, 0777, true);
    $this->git = function (string ...$arguments): string {
        $process = new Process(['git', '-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.test', '-c', 'commit.gpgsign=false', ...$arguments], $this->fpBase, ['GIT_CONFIG_GLOBAL' => '/dev/null']);
        $process->mustRun();

        return $process->getOutput();
    };
    $this->write = function (string $path, string $contents): void {
        is_dir(dirname($this->fpBase.'/'.$path)) || mkdir(dirname($this->fpBase.'/'.$path), 0777, true);
        file_put_contents($this->fpBase.'/'.$path, $contents);
    };
    $this->environment = ['SOLO_PROCESS_ID' => '41', 'CLAUDE_CODE_SESSION_ID' => 'session-a', 'ANTHROPIC_MODEL' => 'model-x'];
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->fpBase);
});

/** @param array<string, mixed> $overrides */
function fpRun(string $ts, array $files, string $scope = 'changed', array $overrides = []): array
{
    return ['kind' => 'run', 'ts' => $ts, 'source' => 'local', 'scope' => $scope, 'who' => ['solo_process_id' => '7'], 'ruleset' => 'r1', 'files' => $files, ...$overrides];
}

/** @param array<string, int> $findings */
function fpFile(string $path, string $hash, array $findings = [], array $patterns = ['data-actions.action'], int $mtime = 1_000): array
{
    return ['path' => $path, 'hash' => $hash, 'mtime' => $mtime, 'new' => false, 'changed_lines' => 2, 'patterns' => $patterns, 'findings' => $findings, 'off_diff' => 0];
}

test('the run log chains each line to the one before it and flags an edited line', function () {
    $file = $this->fpBase.'/storage/standards/runs.jsonl';

    expect(RunLog::append($file, ['kind' => 'run', 'n' => 1]))->toBeTrue()
        ->and(RunLog::append($file, ['kind' => 'run', 'n' => 2]))->toBeTrue()
        ->and(RunLog::append($file, ['kind' => 'run', 'n' => 3]))->toBeTrue();

    $read = RunLog::read($file);
    expect($read['breaks'])->toBe([])
        ->and(array_column($read['records'], 'n'))->toBe([1, 2, 3])
        ->and($read['records'][0]['prev'])->toBeNull()
        ->and(file_get_contents(dirname($file).'/.gitignore'))->toBe("runs.jsonl\n.gitignore\n");

    $lines = file($file, FILE_IGNORE_NEW_LINES);
    $lines[1] = str_replace('"n":2', '"n":0', $lines[1]);
    file_put_contents($file, implode("\n", $lines)."\n");

    expect(RunLog::read($file)['breaks'])->toBe([['line' => 3, 'reason' => 'prev does not match the line before it']]);
});

test('a deleted first line is a chain break', function () {
    $file = $this->fpBase.'/runs.jsonl';
    RunLog::append($file, ['n' => 1]);
    RunLog::append($file, ['n' => 2]);
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    file_put_contents($file, $lines[1]."\n");

    expect(RunLog::read($file)['breaks'][0]['line'])->toBe(1);
});

test('a log write that cannot happen fails open', function () {
    file_put_contents($this->fpBase.'/blocker', 'a file where the directory should be');

    expect(RunLog::append($this->fpBase.'/blocker/runs.jsonl', ['n' => 1]))->toBeFalse();
    (new Recorder($this->fpBase.'/blocker', true))->lookup('standards:pattern', ['x']);
    (new Recorder($this->fpBase.'/no-such-repo', true))->check(['violations' => []], 'main');

    expect(is_file($this->fpBase.'/blocker'))->toBeTrue();
});

test('nothing is logged unless telemetry is on', function () {
    (new Recorder($this->fpBase, false))->lookup('standards:pattern', ['data-actions.action']);
    (new Recorder($this->fpBase, false))->check(['scope' => 'changed', 'violations' => []], 'main');

    expect(is_file($this->fpBase.'/'.RunLog::FILE))->toBeFalse();
});

test('attribution comes from the environment and CI is detected', function () {
    expect(Context::who($this->environment))->toBe(['solo_process_id' => '41', 'solo_project_id' => null, 'session' => 'session-a', 'model' => 'model-x'])
        ->and(Context::who([]))->toBe(['solo_process_id' => null, 'solo_project_id' => null, 'session' => null, 'model' => null])
        ->and(Context::source(['GITHUB_ACTIONS' => 'true']))->toBe('ci')
        ->and(Context::source(['CI' => '1']))->toBe('ci')
        ->and(Context::source([]))->toBe('local');
});

test('the ruleset hash follows the configuration file and not the allowlist', function () {
    ($this->write)('tests/Arch/configuration.php', "<?php return 1;\n");
    $first = Context::ruleset($this->fpBase, 'tests/Arch/configuration.php');
    ($this->write)('tests/Arch/conformance-allowlist.php', "<?php return [];\n");

    expect(Context::ruleset($this->fpBase, 'tests/Arch/configuration.php'))->toBe($first);

    ($this->write)('tests/Arch/configuration.php', "<?php return 2;\n");

    expect(Context::ruleset($this->fpBase, 'tests/Arch/configuration.php'))->not->toBe($first);
});

test('a gate run logs changed files with findings on changed lines only and no source code', function () {
    ($this->git)('init', '--quiet', '--initial-branch=main');
    ($this->write)('composer.json', json_encode(['autoload' => ['psr-4' => ['App\\' => 'app/']]]));
    ($this->write)('app/Old.php', "<?php\n// one\n// two\n// three\n// four\n");
    ($this->write)('app/Untouched.php', "<?php\n");
    ($this->git)('add', '.');
    ($this->git)('commit', '--quiet', '-m', 'Fixture');
    ($this->write)('app/Old.php', "<?php\n// one\n// SECRET_SOURCE_MARKER\n// three\n// four\n");
    ($this->write)('app/Fresh.php', "<?php\n// SECRET_SOURCE_MARKER\n");

    $report = ['scope' => 'changed', 'sha' => 'abc', 'exit_code' => 1, 'paths' => ['app/Fresh.php', 'app/Old.php'], 'violations' => [
        ['rule' => 'actions', 'kind' => 'violation', 'target' => 'app/Old.php', 'check' => 'SECRET_SOURCE_MARKER', 'hint' => 'h', 'line' => 3],
        ['rule' => 'actions', 'kind' => 'violation', 'target' => 'app/Old.php', 'check' => 'c', 'hint' => 'h', 'line' => 5],
        ['rule' => 'controllers', 'kind' => 'violation', 'target' => 'App\\Old', 'check' => 'c', 'hint' => 'h'],
        ['rule' => 'naming', 'kind' => 'violation', 'target' => 'App\\Fresh', 'check' => 'c', 'hint' => 'h'],
        ['rule' => 'naming', 'kind' => 'violation', 'target' => 'app/Untouched.php', 'check' => 'c', 'hint' => 'h'],
    ]];
    (new Recorder($this->fpBase, true, environment: $this->environment))->check($report, 'main');

    $raw = (string) file_get_contents($this->fpBase.'/'.RunLog::FILE);
    $record = RunLog::read($this->fpBase.'/'.RunLog::FILE)['records'][0];
    $files = array_column($record['files'], null, 'path');

    expect($raw)->not->toContain('SECRET_SOURCE_MARKER')
        ->and(array_keys($files))->toBe(['app/Fresh.php', 'app/Old.php'])
        ->and($files['app/Old.php']['findings'])->toBe(['actions' => 1])
        ->and($files['app/Old.php']['off_diff'])->toBe(2)
        ->and($files['app/Old.php']['changed_lines'])->toBe(1)
        ->and($files['app/Fresh.php']['new'])->toBeTrue()
        ->and($files['app/Fresh.php']['findings'])->toBe(['naming' => 1])
        ->and($record['who']['solo_process_id'])->toBe('41')
        ->and($record['source'])->toBe('local')
        ->and($record['ruleset'])->toBeString();
});

test('a lookup logs the leaves it answered with and the paths it targets', function () {
    (new Recorder($this->fpBase, true, environment: $this->environment))->lookup('standards:pattern', Recorder::patternsOf(['type' => 'question', 'candidates' => [['id' => 'ui-page.index'], ['id' => 'ui-page.form']]]), ['app/Http/Controllers/A.php']);

    $record = RunLog::read($this->fpBase.'/'.RunLog::FILE)['records'][0];

    expect($record['kind'])->toBe('lookup')
        ->and($record['tool'])->toBe('standards:pattern')
        ->and($record['patterns'])->toBe(['ui-page.index', 'ui-page.form'])
        ->and($record['paths'])->toBe(['app/Http/Controllers/A.php'])
        ->and(Recorder::patternsOf(['type' => 'plan', 'units' => [['leaf' => 'a'], ['leaf' => 'b']]]))->toBe(['a', 'b'])
        ->and(Recorder::patternsOf(['type' => 'leaf', 'id' => 'c']))->toBe(['c']);
});

test('episodes open on a change and close on the next clean run', function () {
    $episodes = Episodes::build([
        fpRun('2026-11-01T10:00:00+00:00', [fpFile('app/A.php', 'a1', ['actions' => 2]), fpFile('app/B.php', 'b1'), fpFile('app/C.php', 'c1', ['naming' => 1])]),
        fpRun('2026-11-01T10:05:00+00:00', [fpFile('app/A.php', 'a2', ['actions' => 1])], 'file'),
        fpRun('2026-11-01T10:10:00+00:00', [fpFile('app/A.php', 'a3'), fpFile('app/B.php', 'b1')]),
        fpRun('2026-11-01T10:20:00+00:00', [fpFile('app/A.php', 'a4', ['tia' => 1]), fpFile('app/B.php', 'b1')]),
    ]);

    expect(array_map(fn (array $episode): array => [$episode['path'], $episode['status'], $episode['runs'], $episode['first_pass']], $episodes))->toBe([
        ['app/A.php', 'corrected', 3, ['actions' => 2]],
        ['app/B.php', 'first-pass', 1, []],
        ['app/C.php', 'abandoned', 1, ['naming' => 1]],
        ['app/A.php', 'open', 1, ['tia' => 1]],
    ]);
});

test('a lookup before the first write marks the episode consulted', function () {
    $lookup = ['kind' => 'lookup', 'ts' => '2026-11-01T09:00:00+00:00', 'patterns' => ['ui-page.index'], 'paths' => []];
    $written = strtotime('2026-11-01T09:30:00+00:00');
    $episodes = Episodes::build([
        $lookup,
        fpRun('2026-11-01T10:00:00+00:00', [
            fpFile('app/Index.php', 'i1', [], ['ui-page.index'], $written),
            fpFile('app/Other.php', 'o1', [], ['data-actions.action'], $written),
            fpFile('app/Early.php', 'e1', [], ['ui-page.index'], strtotime('2026-11-01T08:00:00+00:00')),
        ]),
    ]);

    expect(array_column($episodes, 'consulted', 'path'))->toBe(['app/Index.php' => true, 'app/Other.php' => false, 'app/Early.php' => false]);
});

test('the report gives the rate and corrections and groups by rule agent and pattern', function () {
    $episodes = Episodes::build([
        fpRun('2026-10-01T10:00:00+00:00', [fpFile('app/Old.php', 'x', ['naming' => 1])], 'file'),
        fpRun('2026-11-01T10:00:00+00:00', [fpFile('app/A.php', 'a1', ['actions' => 2]), fpFile('app/B.php', 'b1', [], ['ui-page.index'])], 'file'),
        fpRun('2026-11-01T10:10:00+00:00', [fpFile('app/A.php', 'a2')], 'file', ['who' => ['solo_process_id' => '8']]),
        fpRun('2026-11-02T10:00:00+00:00', [fpFile('app/Ci.php', 'c1', ['actions' => 1])], 'file', ['source' => 'ci']),
    ]);

    $local = Report::build($episodes, '2026-11-01', 'local', 'pattern');

    expect($local['episodes'])->toBe(2)
        ->and($local['first_pass'])->toBe(1)
        ->and($local['first_pass_rate'])->toBe(0.5)
        ->and($local['corrections_per_episode'])->toBe(0.5)
        ->and($local['rules'])->toBe(['actions' => 2])
        ->and(array_keys($local['groups']))->toBe(['data-actions.action', 'ui-page.index'])
        ->and(Report::build($episodes, null, 'ci')['episodes'])->toBe(1)
        ->and(array_keys(Report::build($episodes, null, null, 'rule')['groups']))->toBe(['(clean)', 'actions', 'naming'])
        ->and(array_keys(Report::build($episodes, null, null, 'agent')['groups']))->toBe([7])
        ->and(Report::text($local))->toContain('Correlation, not causation')->toContain('(n=');
});

test('recompute replays each commit and flags commits the log never gated', function () {
    ($this->git)('init', '--quiet', '--initial-branch=main');
    ($this->write)('README.md', "fixture\n");
    ($this->git)('add', '.');
    ($this->git)('commit', '--quiet', '-m', 'Root');
    ($this->write)('app/A.php', "<?php\n// BAD\n");
    ($this->git)('add', '.');
    ($this->git)('commit', '--quiet', '-m', 'A dirty');
    ($this->write)('app/A.php', "<?php\n// fine\n");
    ($this->write)('app/B.php', "<?php\n");
    ($this->git)('add', '.');
    ($this->git)('commit', '--quiet', '-m', 'A fixed and B added');

    // The fake gate flags each line that says BAD, as the real one would flag a rule.
    $gate = function (string $worktree, array $files): array {
        $violations = [];

        foreach ($files as $file) {
            foreach (file($worktree.'/'.$file, FILE_IGNORE_NEW_LINES) ?: [] as $index => $line) {
                if (str_contains($line, 'BAD')) {
                    $violations[] = ['rule' => 'fixture', 'kind' => 'violation', 'target' => $file, 'check' => 'c', 'hint' => 'h', 'line' => $index + 1];
                }
            }
        }

        return ['violations' => $violations];
    };
    $hash = fn (string $contents): string => substr(hash('sha256', $contents), 0, 16);
    $log = [fpRun('2000-01-01T00:00:00+00:00', [fpFile('app/A.php', $hash("<?php\n// BAD\n"), ['fixture' => 1])])];

    $result = (new Recompute($this->fpBase, $gate))->run('HEAD~2..HEAD', $log, [['line' => 4, 'reason' => 'prev does not match the line before it']]);

    expect($result['commits'])->toBe(2)
        ->and($result['episodes']['episodes'])->toBe(2)
        ->and($result['episodes']['corrected'])->toBe(1)
        ->and($result['episodes']['first_pass'])->toBe(1)
        ->and(array_map(fn (array $mismatch): array => [$mismatch['path'], $mismatch['reason']], $result['mismatches']))->toBe([
            ['app/A.php', 'committed content differs from the last content the log gated'],
            ['app/B.php', 'committed but never gated in the log'],
        ])
        ->and($result['chain_breaks'])->toHaveCount(1)
        ->and(trim(($this->git)('worktree', 'list', '--porcelain')))->not->toContain('standards-recompute');
});

test('recompute finds no mismatch when the log gated each committed file', function () {
    ($this->git)('init', '--quiet', '--initial-branch=main');
    ($this->write)('README.md', "fixture\n");
    ($this->git)('add', '.');
    ($this->git)('commit', '--quiet', '-m', 'Root');
    ($this->write)('app/A.php', "<?php\n");
    ($this->git)('add', '.');
    ($this->git)('commit', '--quiet', '-m', 'A');
    $log = [fpRun('2000-01-01T00:00:00.123456+00:00', [fpFile('app/A.php', substr(hash('sha256', "<?php\n"), 0, 16))])];

    $result = (new Recompute($this->fpBase, fn (): array => ['violations' => []]))->run('HEAD~1..HEAD', $log);

    expect($result['mismatches'])->toBe([]);
});

test('recompute and the log skip files the gate cannot check', function () {
    ($this->git)('init', '--quiet', '--initial-branch=main');
    ($this->write)('README.md', "fixture\n");
    ($this->git)('add', '.');
    ($this->git)('commit', '--quiet', '-m', 'Root');
    ($this->write)('app/A.php', "<?php\n");
    ($this->write)('resources/js/pages/A.vue', "<template />\n");
    ($this->write)('docs/patterns.md', "# Patterns\n");
    ($this->write)('CHANGELOG.md', "# Changelog\n");
    ($this->write)('vendor/acme/Lib.php', "<?php\n");
    ($this->write)('public/build/assets/app.js', "export {}\n");
    ($this->git)('add', '--force', '.');
    ($this->git)('commit', '--quiet', '-m', 'Code and docs');
    $hash = fn (string $contents): string => substr(hash('sha256', $contents), 0, 16);
    $gated = [];
    $gate = function (string $worktree, array $files) use (&$gated): array {
        $gated = $files;

        return ['violations' => []];
    };
    $log = [fpRun('2000-01-01T00:00:00+00:00', [fpFile('app/A.php', $hash("<?php\n")), fpFile('resources/js/pages/A.vue', $hash("<template />\n"))])];

    $result = (new Recompute($this->fpBase, $gate))->run('HEAD~1..HEAD', $log);

    expect($result['mismatches'])->toBe([])
        ->and($gated)->toBe(['app/A.php', 'resources/js/pages/A.vue']);

    ($this->write)('docs/patterns.md', "# Patterns\n\nMore.\n");
    ($this->write)('app/A.php', "<?php\n// more\n");
    $record = (new Recorder($this->fpBase, true, environment: $this->environment))->runRecord(['scope' => 'changed', 'violations' => []], 'HEAD');

    expect(array_column($record['files'], 'path'))->toBe(['app/A.php']);
});

test('recompute needs a range', function () {
    (new Recompute($this->fpBase, fn (): array => []))->run('HEAD', []);
})->throws(RuntimeException::class, 'git range');

test('the CI summary lists the files with findings on changed lines', function () {
    $summary = FirstPassCommand::summary(['sha' => str_repeat('a', 40), 'ruleset' => 'r1', 'files' => [fpFile('app/A.php', 'a', ['actions' => 2]), fpFile('app/B.php', 'b')]]);

    expect($summary)->toContain('1 of 2 changed files clean')
        ->toContain('| `app/A.php` | actions ×2 |')
        ->not->toContain('app/B.php');
});

test('the reusable first-pass workflow keeps its artifact for 90 days and never gates', function () {
    $workflow = Symfony\Component\Yaml\Yaml::parseFile(dirname(__DIR__).'/.github/workflows/first-pass.yml');
    $steps = $workflow['jobs']['first-pass']['steps'];

    expect($workflow['on'])->toHaveKey('workflow_call')
        ->and(json_encode($steps))->toContain('standards:first-pass --ci')
        ->and(end($steps)['with']['retention-days'])->toBe(90);
});

test('a run where the gate could not run opens no episode', function () {
    expect(Episodes::build([fpRun('2026-11-01T10:00:00+00:00', [fpFile('app/A.php', 'a1')], 'changed', ['gate_error' => true])]))->toBe([])
        ->and(FirstPassCommand::summary(['gate_error' => true, 'files' => []]))->toContain('could not run');
});

test('merged logs are read in timestamp order with ties kept in input order', function () {
    $sorted = FirstPassCommand::chronological([
        ['n' => 1, 'ts' => '2026-11-01T10:00:00.500000+00:00'],
        ['n' => 2, 'ts' => '2026-11-01T10:00:00.100000+00:00'],
        ['n' => 3, 'ts' => '2026-11-01T10:00:00.100000+00:00'],
        ['n' => 4, 'ts' => '2026-11-01T09:00:00+00:00'],
    ]);

    expect(array_column($sorted, 'n'))->toBe([4, 2, 3, 1]);
});

test('lookups mark only episodes from their own source as consulted', function () {
    $lookup = ['kind' => 'lookup', 'ts' => '2026-11-01T09:00:00+00:00', 'source' => 'local', 'patterns' => ['ui-page.index'], 'paths' => []];
    $episodes = Episodes::build([$lookup, fpRun('2026-11-01T10:00:00+00:00', [fpFile('app/A.php', 'a', [], ['ui-page.index'], strtotime('2026-11-01T09:30:00+00:00'))], 'file', ['source' => 'ci'])]);

    expect($episodes[0]['consulted'])->toBeNull();
});

test('gap lookups log their leaves and hash suffixed targets count against their file', function () {
    expect(Recorder::patternsOf(['type' => 'gaps', 'leaves' => [['id' => 'g.one'], ['id' => 'g.two']]]))->toBe(['g.one', 'g.two']);

    $entries = (new Recorder($this->fpBase, true))->files(['app/A.php'], ['app/A.php' => ['new' => true, 'lines' => []]], [
        ['rule' => 'no-pixel-pins', 'target' => 'app/A.php#abc123', 'check' => 'c', 'hint' => 'h'],
    ]);

    expect($entries[0]['findings'])->toBe(['no-pixel-pins' => 1]);
});

test('content hashes ignore CRLF line endings', function () {
    ($this->write)('lf.php', "<?php\n// a\n");
    ($this->write)('crlf.php', "<?php\r\n// a\r\n");

    expect(Recorder::hash($this->fpBase.'/crlf.php'))->toBe(Recorder::hash($this->fpBase.'/lf.php'));
});
