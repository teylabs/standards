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
    ['concurrency-draft.yml', 'concurrency-draft', 'workflow'],
    ['draft-guard.yml', 'draft-guard', 'test'],
    ['timeout.yml', 'timeout', 'test'],
    ['php-matrix.yml', 'php-matrix', 'test'],
    ['os-matrix.yaml', 'os-matrix', 'test'],
]);

test('[rule 51] workflow variants enforce boundaries without rejecting supported alternatives', function (Closure $change, array $categories, array $branches, ?string $distribution, bool $artisan) {
    $workflow = Yaml::parseFile(dirname(__DIR__).'/fixtures/CI/compliant.yml');
    $change($workflow);
    file_put_contents($this->ciBase.'/.github/workflows/tests.yml', Yaml::dump($workflow, 10));

    if (! $artisan) {
        unlink($this->ciBase.'/artisan');
    }

    $findings = (new CiTriggers($this->ciBase, $branches, $distribution))->findings();
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
        $w['jobs']['test']['concurrency'] = ['group' => $w['concurrency']['group'], 'cancel-in-progress' => true];
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
        $w['jobs']['test']['runs-on'] = 'windows-latest';
    }, [], ['main'], 'package', true],
    'unknown does not infer package' => [function (&$w) {
        $w['jobs']['test']['strategy']['matrix']['php-version'] = ['8.4', '8.5'];
    }, [], ['main'], null, false],
    'app override without artisan' => [function (&$w) {
        $w['jobs']['test']['strategy']['matrix']['php-version'] = ['8.4', '8.5'];
    }, ['php-matrix'], ['main'], 'app', false],
    'package Windows required' => [function (&$w) {
        $w['jobs']['test']['runs-on'] = 'windows-latest';
    }, [], ['main'], 'package', false],
    'single PHP' => [function (&$w) {
        $w['jobs']['test']['strategy']['matrix']['php-version'] = ['8.5'];
    }, [], ['main'], null, true],
    'direct nonLinux runner' => [function (&$w) {
        $w['jobs']['test']['runs-on'] = 'macos-latest';
    }, ['os-matrix'], ['main'], null, true],
    'conditional OS expression' => [function (&$w) {
        $w['jobs']['test']['runs-on'] = '${{ matrix.os }}';
        $w['jobs']['test']['strategy']['matrix']['os'] = '${{ github.event_name == \'pull_request\' && fromJSON(\'["ubuntu-latest"]\') || fromJSON(\'["ubuntu-latest", "windows-latest"]\') }}';
    }, ['os-matrix'], ['main'], null, true],
    'conditional direct runner' => [function (&$w) {
        $w['jobs']['test']['runs-on'] = '${{ github.event_name == \'pull_request\' && \'ubuntu-latest\' || \'windows-latest\' }}';
    }, ['os-matrix'], ['main'], null, true],
    'nightly workflow' => [function (&$w) {
        unset($w['on']['pull_request'], $w['jobs']['test']['if']);
        $w['jobs']['test']['runs-on'] = 'macos-latest';
        $w['jobs']['test']['strategy']['matrix']['php-version'] = ['8.4', '8.5'];
    }, ['os-matrix'], ['main'], null, true],
    'nightly job' => [function (&$w) {
        $w['jobs']['test']['if'] = "github.event_name == 'schedule'";
        $w['jobs']['test']['runs-on'] = 'macos-latest';
        $w['jobs']['test']['strategy']['matrix']['php-version'] = ['8.4', '8.5'];
    }, ['os-matrix'], ['main'], null, true],
    'Windows on main' => [function (&$w) {
        $w['jobs']['test']['if'] = "github.event_name == 'push'";
        $w['jobs']['test']['runs-on'] = 'windows-latest';
    }, ['os-matrix'], ['main'], null, true],
    'OS matrix entry even with fixed Linux runner' => [function (&$w) {
        $w['jobs']['test']['strategy']['matrix']['os'] = ['ubuntu-latest', 'windows-latest'];
    }, ['os-matrix'], ['main'], null, true],
    'other nonLinux OS matrix entry with fixed Linux runner' => [function (&$w) {
        $w['jobs']['test']['strategy']['matrix']['os'] = ['ubuntu-latest', 'freebsd'];
    }, ['os-matrix'], ['main'], null, true],
]);

test('[rule 51] draft-aware concurrency checks every applicable scope once', function (Closure $change, array $jobs) {
    $workflow = Yaml::parseFile(dirname(__DIR__).'/fixtures/CI/compliant.yml');
    $change($workflow);
    file_put_contents($this->ciBase.'/.github/workflows/tests.yml', Yaml::dump($workflow, 10));
    $findings = (new CiTriggers($this->ciBase))->findings();

    expect(array_column($findings, 'job'))->toBe($jobs);

    foreach ($findings as $finding) {
        expect($finding['reason'])->toStartWith('concurrency-draft:')->toContain('github.event.pull_request.draft');
    }
})->with([
    'unguarded workflow is exempt' => [function (&$w) {
        unset($w['jobs']['test']['if']);
        $w['jobs']['test']['steps'] = [['run' => 'composer lint']];
        $w['concurrency']['group'] = '${{ github.workflow }}-${{ github.ref }}';
    }, []],
    'guarded non-test job still needs draft isolation' => [function (&$w) {
        $w['jobs']['test']['steps'] = [['run' => 'composer lint']];
        $w['concurrency']['group'] = 'lint';
    }, ['workflow']],
    'job group is checked despite safe workflow group' => [function (&$w) {
        $w['jobs']['test']['concurrency'] = ['group' => 'tests'];
    }, ['test']],
    'job-only shorthand group' => [function (&$w) {
        $w['jobs']['test']['concurrency'] = 'tests';
        unset($w['concurrency']);
    }, ['test']],
    'job concurrency cannot isolate shared workflow group' => [function (&$w) {
        $w['jobs']['test']['concurrency'] = $w['concurrency'];
        $w['jobs']['other'] = $w['jobs']['test'];
        $w['concurrency']['group'] = 'tests';
    }, ['workflow']],
    'unguarded sibling job is exempt' => [function (&$w) {
        $w['jobs']['lint'] = ['concurrency' => 'lint', 'runs-on' => 'ubuntu-latest', 'timeout-minutes' => 5, 'steps' => [['run' => 'composer lint']]];
    }, []],
]);

test('[rule 51] draft references allow expression whitespace and YAML quoting at both concurrency levels', function (string $group, bool $valid) {
    $workflow = Yaml::parseFile(dirname(__DIR__).'/fixtures/CI/compliant.yml');
    $workflow['concurrency'] = Yaml::parse('group: '.$group);
    $workflow['jobs']['test']['concurrency'] = $workflow['concurrency'];
    file_put_contents($this->ciBase.'/.github/workflows/tests.yml', Yaml::dump($workflow, 10));
    $findings = (new CiTriggers($this->ciBase))->findings();

    expect(array_column($findings, 'job'))->toBe($valid ? [] : ['workflow', 'test']);

    foreach ($findings as $finding) {
        expect($finding['reason'])->toStartWith('concurrency-draft:');
    }
})->with([
    'compact unquoted expression' => ['${{github.event.pull_request.draft}}-tests', true],
    'single quoted embedded expression' => ['\'tests-${{  github.event.pull_request.draft  }}-${{ github.ref }}\'', true],
    'double quoted whitespace around property access' => ['"${{ github . event . pull_request . draft }}-tests"', true],
    'reference inside a larger expression' => ['"${{ format(\'{0}-{1}\', github.workflow, github.event.pull_request.draft) }}"', true],
    'literal property name is not an expression' => ['"github.event.pull_request.draft"', false],
    'different property is not draft' => ['${{ github.event.pull_request.draft_status }}', false],
]);

test('[rule 51] each supported test command needs a draft guard', function (string $command) {
    $workflow = Yaml::parseFile(dirname(__DIR__).'/fixtures/CI/compliant.yml');
    unset($workflow['jobs']['test']['if']);
    $workflow['jobs']['test']['steps'] = [['run' => $command]];
    file_put_contents($this->ciBase.'/.github/workflows/tests.yml', Yaml::dump($workflow, 10));
    expect((new CiTriggers($this->ciBase))->findings())->toHaveCount(1);
    expect((new CiTriggers($this->ciBase))->findings()[0]['reason'])->toStartWith('draft-guard:');
})->with(['php artisan test', 'pest --parallel', 'phpunit', 'vendor/bin/pest', './vendor/bin/phpunit', 'npm test', 'composer test', 'npx playwright test', 'npx vitest run']);

test('[rule 51] every workflow and job is inspected, including yaml extensions and malformed YAML', function () {
    copy(dirname(__DIR__).'/fixtures/CI/timeout.yml', $this->ciBase.'/.github/workflows/first.yml');
    copy(dirname(__DIR__).'/fixtures/CI/os-matrix.yaml', $this->ciBase.'/.github/workflows/second.yaml');
    file_put_contents($this->ciBase.'/.github/workflows/broken.yml', 'jobs: [');
    $findings = (new CiTriggers($this->ciBase))->findings();
    expect(array_column($findings, 'workflow'))->toBe(['.github/workflows/broken.yml', '.github/workflows/first.yml', '.github/workflows/second.yaml']);
    expect(array_map(fn ($f) => explode(':', $f['reason'])[0], $findings))->toBe(['yaml', 'timeout', 'os-matrix']);
});

test('[rule 51] distribution detection uses config then composer then artisan, otherwise advisory unknown', function (?string $type, ?string $override, bool $artisan, string $expected) {
    if ($type !== null) {
        file_put_contents($this->ciBase.'/composer.json', json_encode(['type' => $type]));
    }

    if (! $artisan) {
        unlink($this->ciBase.'/artisan');
    }

    copy(dirname(__DIR__).'/fixtures/CI/compliant.yml', $this->ciBase.'/.github/workflows/tests.yml');
    $parser = new CiTriggers($this->ciBase, distribution: $override);
    expect($parser->distribution())->toBe($expected);

    if ($expected === 'unknown') {
        expect($parser->findings())->toBe([]);
        expect(implode(' ', array_column($parser->advisories(), 'reason')))->toContain('Unknown', 'preflight.ci.distribution', 'PREFLIGHT_CI_DISTRIBUTION');
    }
})->with([
    ['project', null, false, 'app'],
    ['library', null, true, 'package'],
    ['laravel-package', null, false, 'package'],
    ['composer-plugin', null, false, 'package'],
    ['custom-distribution', null, false, 'package'],
    ['project', 'package', true, 'package'],
    ['library', 'app', false, 'app'],
    [null, null, true, 'app'],
    [null, null, false, 'unknown'],
]);

test('[rule 51] packages require Windows before merge, not merely somewhere in CI', function (Closure $change, bool $gap) {
    $workflow = Yaml::parseFile(dirname(__DIR__).'/fixtures/CI/compliant.yml');
    $workflow['jobs']['test']['runs-on'] = '${{ matrix.os }}';
    $workflow['jobs']['test']['strategy']['matrix'] = ['os' => ['ubuntu-latest', 'windows-latest'], 'php' => ['8.4', '8.5']];
    $change($workflow);
    file_put_contents($this->ciBase.'/.github/workflows/tests.yml', Yaml::dump($workflow, 10));
    $parser = new CiTriggers($this->ciBase, distribution: 'package');
    expect(array_map(fn ($f) => explode(':', $f['reason'])[0], $parser->findings()))->toBe($gap ? ['windows-gap'] : []);
    expect(implode(' ', array_column($parser->advisories(), 'reason')))->toContain('macOS');
})->with([
    'ready and draft guarded' => [function (&$w) {}, false],
    'unguarded ready job' => [function (&$w) {
        unset($w['jobs']['test']['if']);
        $w['jobs']['test']['steps'] = [];
    }, false],
    'main only' => [function (&$w) {
        $w['jobs']['test']['if'] = "github.event_name == 'push'";
    }, true],
    'nightly only' => [function (&$w) {
        $w['jobs']['test']['if'] = "github.event_name == 'schedule'";
    }, true],
    'workflow main only' => [function (&$w) {
        unset($w['on']['pull_request']);
    }, true],
    'no Windows' => [function (&$w) {
        $w['jobs']['test']['strategy']['matrix']['os'] = ['ubuntu-latest'];
    }, true],
    'draft only' => [function (&$w) {
        $w['jobs']['test']['if'] = 'github.event.pull_request.draft == true';
        $w['jobs']['test']['steps'] = [];
    }, true],
    'excluded Windows' => [function (&$w) {
        $w['jobs']['test']['strategy']['matrix']['exclude'] = [['os' => 'windows-latest']];
    }, true],
    'one Windows PHP excluded still has coverage' => [function (&$w) {
        $w['jobs']['test']['strategy']['matrix']['exclude'] = [['php' => '8.4', 'os' => 'windows-latest']];
    }, false],
    'all Windows combinations excluded with reversed keys' => [function (&$w) {
        $w['jobs']['test']['strategy']['matrix']['exclude'] = [['php' => '8.4', 'os' => 'windows-latest'], ['php' => '8.5', 'os' => 'windows-latest']];
    }, true],
    'included Windows' => [function (&$w) {
        $w['jobs']['test']['strategy']['matrix'] = ['include' => [['os' => 'ubuntu-latest'], ['os' => 'windows-latest']]];
    }, false],
    'event-selected Windows main only' => [function (&$w) {
        $w['jobs']['test']['strategy']['matrix']['os'] = '${{ github.event_name == \'pull_request\' && fromJSON(\'["ubuntu-latest"]\') || fromJSON(\'["ubuntu-latest", "windows-latest"]\') }}';
    }, true],
    'event-selected Windows on PR' => [function (&$w) {
        $w['jobs']['test']['runs-on'] = '${{ github.event_name == \'pull_request\' && \'windows-latest\' || \'ubuntu-latest\' }}';
    }, false],
    'parenthesized ready guard' => [function (&$w) {
        $w['jobs']['test']['if'] = "github.event_name == 'pull_request' && (github.event.pull_request.draft == false || github.event_name == 'push')";
    }, false],
    'parenthesized main guard' => [function (&$w) {
        $w['jobs']['test']['if'] = "github.event_name == 'push' && (github.event.pull_request.draft == false || github.event_name == 'schedule')";
    }, true],
]);

test('[rule 51] platform coverage combines ready legs across workflows and macOS stays advisory', function () {
    $workflow = Yaml::parseFile(dirname(__DIR__).'/fixtures/CI/compliant.yml');
    file_put_contents($this->ciBase.'/.github/workflows/linux.yml', Yaml::dump($workflow, 10));
    $workflow['jobs']['test']['runs-on'] = 'windows-latest';
    file_put_contents($this->ciBase.'/.github/workflows/windows.yml', Yaml::dump($workflow, 10));
    $parser = new CiTriggers($this->ciBase, distribution: 'package');
    expect($parser->findings())->toBe([]);
    expect(implode(' ', array_column($parser->advisories(), 'reason')))->toContain('macOS');
    $workflow['jobs']['test']['runs-on'] = 'macos-latest';
    file_put_contents($this->ciBase.'/.github/workflows/mac.yml', Yaml::dump($workflow, 10));
    expect($parser->findings())->toBe([])->and($parser->advisories())->toBe([]);
});

test('[rule 51] cache suggestions distinguish dependency caches from unrelated caches', function (string $install, array $cache, bool $advisory) {
    $workflow = Yaml::parseFile(dirname(__DIR__).'/fixtures/CI/compliant.yml');
    $workflow['jobs']['test']['steps'] = [['uses' => 'shivammathur/setup-php@v2'], ...$cache, ['run' => $install]];
    file_put_contents($this->ciBase.'/.github/workflows/tests.yml', Yaml::dump($workflow, 10));
    $parser = new CiTriggers($this->ciBase);
    expect($parser->findings())->toBe([]);
    $suggestions = array_filter($parser->advisories(), fn ($f) => str_starts_with($f['reason'], 'cache:'));
    expect($suggestions)->toHaveCount($advisory ? 1 : 0);

    if ($advisory) {
        expect(array_values($suggestions)[0]['reason'])->toContain('Suggest', '45', 'minute boundary');
    }
})->with([
    ['composer install', [], true],
    ['composer update --prefer-dist', [], true],
    ['npm ci', [], true],
    ['npm install', [], true],
    ['npm i --ignore-scripts', [], true],
    ['npm run install', [], false],
    ['composer install', [['uses' => 'ramsey/composer-install@v3']], false],
    ['npm ci', [['uses' => 'actions/setup-node@v6', 'with' => ['cache' => 'npm']]], false],
    ['npm ci', [['uses' => 'actions/cache@v4', 'with' => ['path' => '~/.npm', 'key' => '${{ runner.os }}-${{ hashFiles(\'package-lock.json\') }}']]], false],
    ['composer install', [['uses' => 'actions/cache@v4', 'with' => ['path' => '~/.composer/cache', 'key' => '${{ runner.os }}-${{ hashFiles(\'composer.lock\') }}']]], false],
    ['composer install', [['uses' => 'actions/cache@v4', 'with' => ['path' => '${{ steps.composer-cache.outputs.dir }}', 'key' => 'composer-cache']]], false],
    ['npm ci', [['uses' => 'actions/cache@v4', 'with' => ['path' => 'public/build', 'key' => 'assets']]], true],
    ['composer install', [['uses' => 'actions/setup-node@v6', 'with' => ['cache' => 'npm']]], true],
]);
