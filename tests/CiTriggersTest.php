<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;
use Tey\Standards\CiTriggers;

beforeEach(function () {
    $this->ciBase = sys_get_temp_dir().'/ci-triggers-'.bin2hex(random_bytes(6));
    mkdir($this->ciBase.'/.github/workflows', 0777, true);
    touch($this->ciBase.'/artisan');
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->ciBase);
});

test('[rule 51] each fixture isolates its finding and the standard workflow passes', function (string $fixture, ?string $category, string $job) {
    $extension = pathinfo($fixture, PATHINFO_EXTENSION);
    copy(dirname(__DIR__).'/fixtures/CI/'.$fixture, $this->ciBase.'/.github/workflows/tests.'.$extension);
    $findings = (new CiTriggers($this->ciBase))->findings();

    if ($category === null) {
        expect($findings)->toBe([]);
    } else {
        expect($findings)->toHaveCount(1);
        expect($findings[0])->toMatchArray(['workflow' => '.github/workflows/tests.'.$extension, 'job' => $job]);
        expect($findings[0]['reason'])->toStartWith($category.':')->not->toBe($category.':');
    }
})->with([
    ['compliant.yml', null, 'test'],
    ['push-branches.yml', 'push-branches', 'workflow'],
    ['pull-request-types.yml', 'pull-request-types', 'test'],
    ['concurrency.yml', 'concurrency', 'test'],
    ['draft-guard.yml', 'draft-guard', 'test'],
    ['timeout.yml', 'timeout', 'test'],
    ['php-matrix.yml', 'php-matrix', 'test'],
    ['os-matrix.yaml', 'os-matrix', 'test'],
]);

test('[rule 51] workflow variants enforce boundaries without rejecting supported alternatives', function (Closure $change, array $categories, array $branches, ?bool $package, bool $artisan) {
    $workflow = Yaml::parseFile(dirname(__DIR__).'/fixtures/CI/compliant.yml');
    $change($workflow);
    file_put_contents($this->ciBase.'/.github/workflows/tests.yml', Yaml::dump($workflow, 10));

    if (! $artisan) {
        unlink($this->ciBase.'/artisan');
    }

    $findings = (new CiTriggers($this->ciBase, $branches, $package))->findings();
    expect(array_map(fn (array $finding): string => explode(':', $finding['reason'])[0], $findings))->toBe($categories);
})->with([
    'push shorthand' => [function (&$w) {
        $w['on'] = 'push';
    }, ['push-branches'], ['main'], null, true],
    'event list' => [function (&$w) {
        $w['on'] = ['push', 'pull_request'];
    }, ['push-branches', 'pull-request-types'], ['main'], null, true],
    'push no filter' => [function (&$w) {
        $w['on']['push'] = null;
    }, ['push-branches'], ['main'], null, true],
    'push wildcard' => [function (&$w) {
        $w['on']['push']['branches'] = ['*'];
    }, ['push-branches'], ['main'], null, true],
    'push other branch' => [function (&$w) {
        $w['on']['push']['branches'] = ['develop'];
    }, ['push-branches'], ['main'], null, true],
    'configured branches' => [function (&$w) {
        $w['on']['push']['branches'] = ['trunk'];
    }, [], ['trunk'], null, true],
    'tag only' => [function (&$w) {
        $w['on']['push'] = ['tags' => ['v*']];
    }, [], ['main'], null, true],
    'tags do not exempt broad branches' => [function (&$w) {
        $w['on']['push'] = ['tags' => ['v*'], 'branches' => ['*']];
    }, ['push-branches'], ['main'], null, true],
    'boolean on key' => [function (&$w) {
        $w[true] = $w['on'];
        unset($w['on']);
    }, [], ['main'], null, true],
    'boolean key still detects push' => [function (&$w) {
        $w[true] = ['push' => null];
        unset($w['on']);
    }, ['push-branches'], ['main'], null, true],
    'PR types missing' => [function (&$w) {
        $w['on']['pull_request'] = null;
    }, ['pull-request-types'], ['main'], null, true],
    'job concurrency' => [function (&$w) {
        $w['jobs']['test']['concurrency'] = $w['concurrency'];
        unset($w['concurrency']);
    }, [], ['main'], null, true],
    'one job missing concurrency' => [function (&$w) {
        $w['jobs']['test']['concurrency'] = $w['concurrency'];
        unset($w['concurrency']);
        $w['jobs']['lint'] = ['runs-on' => 'ubuntu-latest', 'timeout-minutes' => 5, 'steps' => [['run' => 'composer lint']]];
    }, ['concurrency'], ['main'], null, true],
    'literal cancellation' => [function (&$w) {
        $w['concurrency']['cancel-in-progress'] = true;
    }, ['concurrency'], ['main'], null, true],
    'literal expression cancellation' => [function (&$w) {
        $w['concurrency']['cancel-in-progress'] = '${{ true }}';
    }, ['concurrency'], ['main'], null, true],
    'job cancellation overrides workflow' => [function (&$w) {
        $w['jobs']['test']['concurrency'] = ['group' => 'tests', 'cancel-in-progress' => true];
    }, ['concurrency'], ['main'], null, true],
    'lint needs no draft guard or ready type' => [function (&$w) {
        unset($w['jobs']['test']['if']);
        $w['jobs']['test']['steps'] = [['run' => "composer lint\nnpm run lint"]];
        $w['on']['pull_request'] = null;
    }, [], ['main'], null, true],
    'negated draft guard' => [function (&$w) {
        $w['jobs']['test']['if'] = '!github.event.pull_request.draft';
    }, [], ['main'], null, true],
    'draft mention is not guard' => [function (&$w) {
        $w['jobs']['test']['if'] = 'github.event.pull_request.draft == true';
    }, ['draft-guard'], ['main'], null, true],
    'unconditional PHP expression' => [function (&$w) {
        $w['jobs']['test']['strategy']['matrix']['php-version'] = '${{ fromJSON(\'["8.4", "8.5"]\') }}';
    }, ['php-matrix'], ['main'], null, true],
    'matrix include versions' => [function (&$w) {
        $w['jobs']['test']['strategy']['matrix'] = ['include' => [['php' => '8.4'], ['php' => '8.5']]];
    }, ['php-matrix'], ['main'], null, true],
    'include adds a PHP version' => [function (&$w) {
        $w['jobs']['test']['strategy']['matrix'] = ['php-version' => ['8.5'], 'include' => [['php-version' => '8.4']]];
    }, ['php-matrix'], ['main'], null, true],
    'literal whole matrix expression' => [function (&$w) {
        $w['jobs']['test']['strategy']['matrix'] = '${{ fromJSON(\'{"php-version":["8.4","8.5"]}\') }}';
    }, ['php-matrix'], ['main'], null, true],
    'single whole matrix expression' => [function (&$w) {
        $w['jobs']['test']['strategy']['matrix'] = '${{ fromJSON(\'{"php-version":["8.5"]}\') }}';
    }, [], ['main'], null, true],
    'include adds a nonLinux runner' => [function (&$w) {
        $w['jobs']['test']['runs-on'] = '${{ matrix.os }}';
        $w['jobs']['test']['strategy']['matrix']['os'] = ['ubuntu-latest'];
        $w['jobs']['test']['strategy']['matrix']['include'] = [['os' => 'macos-latest']];
    }, ['os-matrix'], ['main'], null, true],
    'package override' => [function (&$w) {
        $w['jobs']['test']['strategy']['matrix']['php-version'] = ['8.4', '8.5'];
    }, [], ['main'], true, true],
    'package auto detection' => [function (&$w) {
        $w['jobs']['test']['strategy']['matrix']['php-version'] = ['8.4', '8.5'];
    }, [], ['main'], null, false],
    'app override without artisan' => [function (&$w) {
        $w['jobs']['test']['strategy']['matrix']['php-version'] = ['8.4', '8.5'];
    }, ['php-matrix'], ['main'], false, false],
    'package still needs Linux' => [function (&$w) {
        $w['jobs']['test']['runs-on'] = 'windows-latest';
    }, ['os-matrix'], ['main'], true, false],
    'single PHP' => [function (&$w) {
        $w['jobs']['test']['strategy']['matrix']['php-version'] = ['8.5'];
    }, [], ['main'], null, true],
    'direct nonLinux runner' => [function (&$w) {
        $w['jobs']['test']['runs-on'] = 'macos-latest';
    }, ['os-matrix'], ['main'], null, true],
    'conditional OS expression' => [function (&$w) {
        $w['jobs']['test']['runs-on'] = '${{ matrix.os }}';
        $w['jobs']['test']['strategy']['matrix']['os'] = '${{ github.event_name == \'pull_request\' && fromJSON(\'["ubuntu-latest"]\') || fromJSON(\'["ubuntu-latest", "windows-latest"]\') }}';
    }, [], ['main'], null, true],
    'conditional direct runner' => [function (&$w) {
        $w['jobs']['test']['runs-on'] = '${{ github.event_name == \'pull_request\' && \'ubuntu-latest\' || \'windows-latest\' }}';
    }, [], ['main'], null, true],
    'nightly workflow' => [function (&$w) {
        unset($w['on']['pull_request'], $w['jobs']['test']['if']);
        $w['jobs']['test']['runs-on'] = 'macos-latest';
        $w['jobs']['test']['strategy']['matrix']['php-version'] = ['8.4', '8.5'];
    }, [], ['main'], null, true],
    'nightly job' => [function (&$w) {
        $w['jobs']['test']['if'] = "github.event_name == 'schedule'";
        $w['jobs']['test']['runs-on'] = 'macos-latest';
        $w['jobs']['test']['strategy']['matrix']['php-version'] = ['8.4', '8.5'];
    }, [], ['main'], null, true],
]);

test('[rule 51] each supported test command needs a draft guard', function (string $command) {
    $workflow = Yaml::parseFile(dirname(__DIR__).'/fixtures/CI/compliant.yml');
    unset($workflow['jobs']['test']['if']);
    $workflow['jobs']['test']['steps'] = [['run' => $command]];
    file_put_contents($this->ciBase.'/.github/workflows/tests.yml', Yaml::dump($workflow, 10));
    expect((new CiTriggers($this->ciBase))->findings())->toHaveCount(1);
    expect((new CiTriggers($this->ciBase))->findings()[0]['reason'])->toStartWith('draft-guard:');
})->with(['php artisan test', 'pest --parallel', 'phpunit', 'vendor/bin/pest', './vendor/bin/phpunit', 'npm test', 'npx playwright test', 'npx vitest run']);

test('[rule 51] every workflow and job is inspected, including yaml extensions and malformed YAML', function () {
    copy(dirname(__DIR__).'/fixtures/CI/timeout.yml', $this->ciBase.'/.github/workflows/first.yml');
    copy(dirname(__DIR__).'/fixtures/CI/os-matrix.yaml', $this->ciBase.'/.github/workflows/second.yaml');
    file_put_contents($this->ciBase.'/.github/workflows/broken.yml', 'jobs: [');
    $findings = (new CiTriggers($this->ciBase))->findings();
    expect(array_column($findings, 'workflow'))->toBe(['.github/workflows/broken.yml', '.github/workflows/first.yml', '.github/workflows/second.yaml']);
    expect(array_map(fn ($f) => explode(':', $f['reason'])[0], $findings))->toBe(['yaml', 'timeout', 'os-matrix']);
});
