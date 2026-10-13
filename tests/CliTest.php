<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

beforeEach(function () {
    $this->cliBase = sys_get_temp_dir().'/standards cli-'.bin2hex(random_bytes(6));
    mkdir($this->cliBase.'/.github/workflows', 0777, true);
    file_put_contents($this->cliBase.'/composer.json', json_encode(['type' => 'project'], JSON_THROW_ON_ERROR));
    copy(dirname(__DIR__).'/fixtures/CI/compliant.yml', $this->cliBase.'/.github/workflows/tests.yml');
    $this->cli = function (array $arguments = [], array $environment = []): Process {
        $process = new Process(
            [PHP_BINARY, dirname(__DIR__).'/bin/standards', ...$arguments],
            $this->cliBase,
            [...array_fill_keys(['PREFLIGHT_CI_DEFAULT_BRANCHES', 'PREFLIGHT_CI_DISTRIBUTION', 'PREFLIGHT_CI_ENFORCE'], false), ...$environment],
        );
        $process->run();

        return $process;
    };
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->cliBase);
});

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
})->with([
    'unknown command' => [['deploy'], []],
    'unknown option' => [['ci', '--strcit'], []],
    'distribution value' => [['ci', '--distribution=unknown'], []],
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
