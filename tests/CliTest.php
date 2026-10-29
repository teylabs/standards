<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Tey\Standards\Preflight\CoreCheck;

beforeEach(function () {
    $this->cliBase = sys_get_temp_dir().'/standards cli-'.bin2hex(random_bytes(6));
    mkdir($this->cliBase.'/.github/workflows', 0777, true);
    file_put_contents($this->cliBase.'/composer.json', json_encode(['type' => 'project'], JSON_THROW_ON_ERROR));
    copy(dirname(__DIR__).'/fixtures/CI/compliant.yml', $this->cliBase.'/.github/workflows/tests.yml');
    $this->cli = function (array $arguments = [], array $environment = []): Process {
        $process = new Process(
            [PHP_BINARY, dirname(__DIR__).'/bin/standards', ...$arguments],
            $this->cliBase,
            [...array_fill_keys(['PREFLIGHT_CI_DEFAULT_BRANCHES', 'PREFLIGHT_CI_DISTRIBUTION', 'PREFLIGHT_CI_ENFORCE', 'PREFLIGHT_CI_DRAFTS', 'GITHUB_EVENT_PATH'], false), ...$environment],
        );
        $process->run();

        return $process;
    };
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->cliBase);
});

test('CLI and app config agree on full drafts with public, private and unknown event visibility', function (?bool $private, string $status, int $exit) {
    $workflow = Yaml::parseFile(dirname(__DIR__).'/fixtures/CI/draft-guard.yml');
    $workflow['jobs']['test']['steps'] = [['run' => 'composer test']];
    file_put_contents($this->cliBase.'/.github/workflows/tests.yml', Yaml::dump($workflow, 10));
    $eventPath = $this->cliBase.'/event.json';

    if ($private !== null) {
        file_put_contents($eventPath, json_encode(['repository' => ['private' => $private]]));
    }

    $process = ($this->cli)(['ci', '--strict', '--enforce', '--json', '--drafts=full'], ['GITHUB_EVENT_PATH' => $eventPath]);
    $report = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    $previous = getenv('GITHUB_EVENT_PATH');
    putenv('GITHUB_EVENT_PATH='.$eventPath);

    try {
        $result = (new CoreCheck('ci.triggers', new Repository(['preflight' => ['ci' => ['drafts' => 'full']]]), $this->cliBase))->run();
        expect($result->status->value)->toBe($status);
        expect($process->getExitCode())->toBe($exit);
        expect(array_map(fn ($f) => explode(':', $f['reason'])[0], $report['findings']))
            ->toBe($private === true ? ['drafts-full-private', 'draft-guard'] : []);
        expect(array_map(fn ($f) => explode(':', $f['reason'])[0], $report['advisories']))
            ->toBe($private === null ? ['drafts-full-unverified'] : []);

        foreach ([...$report['findings'], ...$report['advisories']] as $finding) {
            expect($result->message)->toContain($finding['reason']);
        }
    } finally {
        putenv($previous === false ? 'GITHUB_EVENT_PATH' : 'GITHUB_EVENT_PATH='.$previous);
    }
})->with([
    'public' => [false, 'pass', 0],
    'private' => [true, 'fail', 1],
    'unknown' => [null, 'warn', 0],
]);

test('draft settings use flags before env before extra before lint defaults', function (array $flags, array $environment, array $extra, array $categories) {
    copy(dirname(__DIR__).'/fixtures/CI/draft-guard.yml', $this->cliBase.'/.github/workflows/tests.yml');
    file_put_contents($this->cliBase.'/composer.json', json_encode(['type' => 'project', 'extra' => ['tey-standards' => ['ci' => $extra]]]));
    $process = ($this->cli)(['ci', '--strict', '--enforce', '--json', ...$flags], $environment);
    $report = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    expect($process->getExitCode())->toBe($categories === [] ? 0 : 1);
    expect(array_map(fn ($f) => explode(':', $f['reason'])[0], $report['findings']))->toBe($categories);

    if (in_array('drafts-invalid', $categories, true)) {
        expect($report['findings'][0]['reason'])->toContain(json_encode($environment['PREFLIGHT_CI_DRAFTS'] ?? $extra['drafts']));
    }
})->with([
    'default lint' => [[], [], [], ['draft-guard']],
    'extra full' => [[], [], ['drafts' => 'full'], []],
    'env lint overrides extra full' => [[], ['PREFLIGHT_CI_DRAFTS' => 'lint'], ['drafts' => 'full'], ['draft-guard']],
    'env full overrides extra lint' => [[], ['PREFLIGHT_CI_DRAFTS' => 'full'], ['drafts' => 'lint'], []],
    'flag lint overrides env full' => [['--drafts=lint'], ['PREFLIGHT_CI_DRAFTS' => 'full'], [], ['draft-guard']],
    'flag full overrides invalid env' => [['--drafts=full'], ['PREFLIGHT_CI_DRAFTS' => 'bogus'], [], []],
    'invalid env overrides extra full' => [[], ['PREFLIGHT_CI_DRAFTS' => 'bogus'], ['drafts' => 'full'], ['drafts-invalid', 'draft-guard']],
    'invalid extra is a finding' => [[], [], ['drafts' => 'bogus'], ['drafts-invalid', 'draft-guard']],
    'null extra is invalid rather than the default' => [[], [], ['drafts' => null], ['drafts-invalid', 'draft-guard']],
]);

test('the CLI reports compliant workflows as schema 1 JSON on stdout only', function () {
    $process = ($this->cli)(['ci', '--strict', '--enforce', '--json']);
    $report = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getErrorOutput())->toBe('')
        ->and($report)->toBe(['schema' => 1, 'findings' => [], 'advisories' => [], 'enforce' => true, 'exit_code' => 0]);
});

test('requirement findings fail only with strict and enforcement from any supported source', function (array $flags, array $environment, array $extra, bool $enforce, int $exit) {
    copy(dirname(__DIR__).'/fixtures/CI/timeout.yml', $this->cliBase.'/.github/workflows/tests.yml');
    file_put_contents($this->cliBase.'/composer.json', json_encode(['type' => 'project', 'extra' => ['tey-standards' => ['ci' => $extra]]], JSON_THROW_ON_ERROR));
    $process = ($this->cli)(['ci', '--json', ...$flags], $environment);
    $report = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    expect($process->getExitCode())->toBe($exit)
        ->and($process->getErrorOutput())->toBe('')
        ->and($report['enforce'])->toBe($enforce)
        ->and($report['exit_code'])->toBe($exit)
        ->and($report['findings'])->toHaveCount(1)
        ->and($report['findings'][0])->toMatchArray(['workflow' => '.github/workflows/tests.yml', 'job' => 'test'])
        ->and($report['findings'][0]['reason'])->toStartWith('timeout:');
})->with([
    'plain findings' => [[], [], [], false, 0],
    'strict advisory' => [['--strict'], [], [], false, 0],
    'flag enforcement' => [['--strict', '--enforce'], [], [], true, 1],
    'env enforcement' => [['--strict'], ['PREFLIGHT_CI_ENFORCE' => 'true'], [], true, 1],
    'extra enforcement' => [['--strict'], [], ['enforce' => true], true, 1],
    'enforce without strict' => [['--enforce'], [], [], true, 0],
    'env false beats extra true' => [['--strict'], ['PREFLIGHT_CI_ENFORCE' => 'false'], ['enforce' => true], false, 0],
    'flag beats env false' => [['--strict', '--enforce'], ['PREFLIGHT_CI_ENFORCE' => 'false'], [], true, 1],
]);

test('branch and distribution settings use flags before env before extra before defaults', function () {
    $workflow = Yaml::parseFile($this->cliBase.'/.github/workflows/tests.yml');
    $workflow['on']['push']['branches'] = ['trunk', '*.x'];
    file_put_contents($this->cliBase.'/.github/workflows/tests.yml', Yaml::dump($workflow, 10));
    file_put_contents($this->cliBase.'/composer.json', json_encode([
        'type' => 'library',
        'extra' => ['tey-standards' => ['ci' => ['default-branches' => ['trunk', '*.x'], 'distribution' => 'app']]],
    ], JSON_THROW_ON_ERROR));

    $extra = json_decode(($this->cli)(['ci', '--json'])->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect($extra['findings'])->toBe([]);

    $environment = ['PREFLIGHT_CI_DEFAULT_BRANCHES' => 'main', 'PREFLIGHT_CI_DISTRIBUTION' => 'package'];
    $env = json_decode(($this->cli)(['ci', '--json'], $environment)->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect(array_map(fn (array $finding): string => explode(':', $finding['reason'])[0], $env['findings']))->toBe(['push-branches', 'windows-gap']);

    $flags = json_decode(($this->cli)(['ci', '--json', '--default-branches=trunk,*.x', '--distribution=app', '--base='.$this->cliBase.'/'], $environment)->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect($flags['findings'])->toBe([]);

    file_put_contents($this->cliBase.'/composer.json', '{"type":"library"}');
    $defaults = json_decode(($this->cli)(['ci', '--json'])->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect(array_map(fn (array $finding): string => explode(':', $finding['reason'])[0], $defaults['findings']))->toBe(['push-branches', 'windows-gap']);
});

test('base selects the repository independently of the working directory', function () {
    $package = ($this->cli)(['ci', '--strict', '--json', '--base='.dirname(__DIR__)]);
    $report = json_decode($package->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    expect($package->getExitCode())->toBe(0)
        ->and($report['enforce'])->toBeTrue()
        ->and($report['findings'])->toBe([]);
});

test('missing workflows stay unknown advisories even with strict enforcement', function () {
    (new Filesystem)->deleteDirectory($this->cliBase.'/.github');
    $process = ($this->cli)(['ci', '--strict', '--enforce', '--json']);
    $report = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    expect($process->getExitCode())->toBe(0)
        ->and($report['findings'])->toBe([])
        ->and($report['advisories'])->toHaveCount(1)
        ->and($report['advisories'][0]['reason'])->toStartWith('unknown:');

    $invalid = ($this->cli)(['ci', '--strict', '--enforce', '--json'], ['PREFLIGHT_CI_DRAFTS' => 'bogus']);
    $report = json_decode($invalid->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect($invalid->getExitCode())->toBe(1);
    expect($report['findings'][0]['reason'])->toStartWith('drafts-invalid:')->toContain('"bogus"');
});

test('human output lists requirements before advisories and summarizes enforcement and exit', function () {
    file_put_contents($this->cliBase.'/composer.json', '{"type":"library"}');
    $process = ($this->cli)(['ci', '--strict', '--enforce']);
    $output = $process->getOutput();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toBe('')
        ->and($output)->toMatch('/Workflow\s+Job\s+Finding/')
        ->and($output)->toMatch('/Requirements[\s\S]*windows-gap:[\s\S]*Advisories[\s\S]*macos:/')
        ->and($output)->toContain('1 requirement findings, 1 advisories; enforce on; exit code 1.');
});

test('invalid CLI usage exits 2 with a useful stderr message', function (array $arguments, array $environment, ?string $composer = null) {
    if ($composer !== null) {
        file_put_contents($this->cliBase.'/composer.json', $composer);
    }

    $process = ($this->cli)($arguments, $environment);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())->toContain('standards ci:', 'Usage: standards ci');

    if (in_array('--drafts=bogus', $arguments, true)) {
        expect($process->getErrorOutput())->toContain('--drafts must be lint or full; got bogus.');
    }
})->with([
    'unknown command' => [['deploy'], []],
    'unknown option' => [['ci', '--strcit'], []],
    'distribution value' => [['ci', '--distribution=unknown'], []],
    'drafts value' => [['ci', '--drafts=bogus'], []],
    'missing option value' => [['ci', '--base'], []],
    'empty base' => [['ci', '--base='], []],
    'empty branch' => [['ci', '--default-branches=main,'], []],
    'invalid enforcement' => [['ci'], ['PREFLIGHT_CI_ENFORCE' => 'maybe']],
    'invalid extra branches' => [['ci'], [], '{"extra":{"tey-standards":{"ci":{"default-branches":[]}}}}'],
    'invalid extra distribution' => [['ci'], [], '{"extra":{"tey-standards":{"ci":{"distribution":"unknown"}}}}'],
    'invalid extra enforcement' => [['ci'], [], '{"extra":{"tey-standards":{"ci":{"enforce":"maybe"}}}}'],
    'malformed composer' => [['ci'], [], '{'],
]);

test('no command and help print usage successfully', function (array $arguments) {
    $process = ($this->cli)($arguments);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getErrorOutput())->toBe('')
        ->and($process->getOutput())->toContain('Usage: standards ci');
})->with([[[]], [['--help']], [['ci', '--help']]]);

test('[rule 51] the README\'s fixture-CI job on older PHP passes bin/standards ci and matches its fixture byte for byte', function () {
    $fixture = file_get_contents(dirname(__DIR__).'/fixtures/CI/adoption-fixtures.yml');
    unlink($this->cliBase.'/.github/workflows/tests.yml');
    copy(dirname(__DIR__).'/fixtures/CI/adoption-fixtures.yml', $this->cliBase.'/.github/workflows/adoption-fixtures.yml');
    $run = fn (string $distribution): array => json_decode(($this->cli)(['ci', '--json', '--strict', '--enforce', '--base='.$this->cliBase, '--distribution='.$distribution])->getOutput(), true);

    // An app: no requirement and no advisory (timeout, triggers, concurrency, draft guard and the cache all pass).
    $app = $run('app');
    // A distributed package: only the repository-wide Windows requirement, which its main test workflow carries.
    $package = $run('package');

    expect(file_get_contents(dirname(__DIR__).'/README.md'))->toContain("```yaml\n".$fixture."```\n")
        ->and($fixture)->toContain('timeout-minutes: 10', "php-version: '8.4'", 'runs-on: ubuntu-latest', "hashFiles('tools/standards/composer.lock')", 'composer install --working-dir=tools/standards', 'tools/standards/vendor/bin/standards adoption --base "$fixture" --inventory resources/adoption/surfaces.json --json')
        ->and($app)->toMatchArray(['findings' => [], 'advisories' => [], 'enforce' => true, 'exit_code' => 0])
        ->and(array_column($package['findings'] ?? [], 'reason'))->toHaveCount(1)
        ->and($package['findings'][0]['reason'])->toStartWith('windows-gap:');

    // Negative: the same job without its timeout fails rule 51.
    file_put_contents($this->cliBase.'/.github/workflows/adoption-fixtures.yml', str_replace("    timeout-minutes: 10\n", '', $fixture));
    expect(array_map(fn (array $finding): string => explode(':', $finding['reason'])[0], $run('app')['findings']))->toBe(['timeout']);
});
