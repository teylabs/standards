<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Tey\Standards\Conformance;
use Tey\Standards\Configuration;
use Tey\Standards\Enforcement\CheckReport;
use Tey\Standards\Enforcement\Extractor;
use Tey\Standards\Enforcement\Gate;
use Tey\Standards\Patterns\TreeCatalog;

/**
 * An app in the older shape: its ConformanceTest builds the Configuration inline, here the Bad fixture's (so there is drift to compare), reading
 * two top-level variables, and there is no tests/Arch/configuration.php.
 */
function inlineApp(string $expressionTail = ''): string
{
    $base = sys_get_temp_dir().'/standards-extract-'.bin2hex(random_bytes(6));
    mkdir($base.'/tests/Arch', 0777, true);
    file_put_contents($base.'/composer.json', json_encode(['name' => 'acme/inline']));
    $fixture = var_export(dirname(__DIR__).'/fixtures/Bad', true);
    file_put_contents($base.'/tests/Arch/ConformanceTest.php', <<<PHP
        <?php

        declare(strict_types=1);

        use Tey\Standards\Conformance;
        use Tey\Standards\Configuration;

        \$fixture = {$fixture};
        \$namespace = 'FixtureBad';

        foreach (Conformance::TESTS as \$rule => \$name) {
            arch(\$name, function () use (\$rule, \$fixture, \$namespace): void {
                static \$preset;
                \$preset ??= new Conformance(new Configuration(
                    base: \$fixture,
                    classRoots: ['src' => \$namespace],
                    sourceRoots: ['src', 'routes'],
                    uiRoots: ['src/UI'],
                    uiNamespaces: [\$namespace.'\\\\UI'],
                    hostNamespaces: [\$namespace],
                    controllerRoots: ['src/Domain/*/Http/Controllers'],
                    routeRoots: ['routes'],
                    controllerSuffix: 'Controller',
                    routeDirectory: 'routes',
                    viewModelRoots: ['src/Domain/*/ViewModels', 'src/UI'],
                    viewModelBases: ['FixtureGood\\\\UI\\\\TypedViewModel'],
                    typedViewModel: 'FixtureGood\\\\UI\\\\TypedViewModel',
                    viewModelSuffix: 'ViewModel',
                    pageRoots: ['pages'],
                    pageScaffolds: ['pages'],
                    placements: ['Policy' => ['src/Domain/*/Policies'], 'Capability' => ['src/Domain/*/Enums']],
                    retired: [\$namespace.'\\\\Retired' => ['src/Retired']],
                    discoveryRoots: ['src/Domain', 'src/UI'],
                    developmentNamespaces: ['Pest', 'FixtureGood\\\\UI\\\\SafeAttribute'],
                    safeAttributes: ['FixtureGood\\\\UI\\\\SafeAttribute'],
                    mediaTrait: 'FixtureGood\\\\UI\\\\MediaBehaviour',
                    mediaInterface: 'FixtureGood\\\\UI\\\\MediaContract',
                    optionalRules: ['ui plus', 'ui new-tab', 'ui dropzone', 'ui toast'],{$expressionTail}
                ));
                \$preset->assert((string) \$rule);
            });
        }

        PHP);
    ExtractTemp::$dirs[] = $base;

    return $base;
}

/** The inline test's Configuration, built here directly: the Bad fixture's, as tests/ConformanceTest.php builds it. */
function badConfiguration(): Configuration
{
    $namespace = 'FixtureBad';

    return new Configuration(
        base: dirname(__DIR__).'/fixtures/Bad',
        classRoots: ['src' => $namespace],
        sourceRoots: ['src', 'routes'],
        uiRoots: ['src/UI'],
        uiNamespaces: [$namespace.'\\UI'],
        hostNamespaces: [$namespace],
        controllerRoots: ['src/Domain/*/Http/Controllers'],
        routeRoots: ['routes'],
        controllerSuffix: 'Controller',
        routeDirectory: 'routes',
        viewModelRoots: ['src/Domain/*/ViewModels', 'src/UI'],
        viewModelBases: ['FixtureGood\\UI\\TypedViewModel'],
        typedViewModel: 'FixtureGood\\UI\\TypedViewModel',
        viewModelSuffix: 'ViewModel',
        pageRoots: ['pages'],
        pageScaffolds: ['pages'],
        placements: ['Policy' => ['src/Domain/*/Policies'], 'Capability' => ['src/Domain/*/Enums']],
        retired: [$namespace.'\\Retired' => ['src/Retired']],
        discoveryRoots: ['src/Domain', 'src/UI'],
        developmentNamespaces: ['Pest', 'FixtureGood\\UI\\SafeAttribute'],
        safeAttributes: ['FixtureGood\\UI\\SafeAttribute'],
        mediaTrait: 'FixtureGood\\UI\\MediaBehaviour',
        mediaInterface: 'FixtureGood\\UI\\MediaContract',
        optionalRules: ['ui plus', 'ui new-tab', 'ui dropzone', 'ui toast'],
    );
}

/** The Configuration the rewritten test now builds: its one expression, evaluated as Pest would run it from tests/Arch. */
function requiredConfiguration(string $base): Configuration
{
    $test = (string) file_get_contents($base.'/tests/Arch/ConformanceTest.php');
    preg_match("/require __DIR__\\.'([^']+)'/", $test, $match);

    return (static fn (string $path): mixed => require $path)($base.'/tests/Arch'.$match[1]);
}

final class ExtractTemp
{
    /** @var list<string> */
    public static array $dirs = [];
}

afterEach(function () {
    foreach (ExtractTemp::$dirs as $base) {
        (new Filesystem)->deleteDirectory($base);
    }
    ExtractTemp::$dirs = [];
});

test('standards:check names --extract when an Arch test builds the Configuration inline', function () {
    $base = inlineApp();
    $report = CheckReport::build($base, 'tests/Arch/configuration.php', null, 'full', new DateTimeImmutable('2026-10-28'), TreeCatalog::shipped());

    expect($report['exit_code'])->toBe(1)
        ->and($report['violations'][0]['check'])->toBe('Conformance configuration tests/Arch/configuration.php not found.')
        ->and($report['violations'][0]['hint'])->toStartWith('tests/Arch/ConformanceTest.php builds the Configuration inline. Run php artisan standards:install --extract');
});

test('standards:check names standards:install when there is nothing to extract', function () {
    $base = sys_get_temp_dir().'/standards-extract-'.bin2hex(random_bytes(6));
    mkdir($base, 0777, true);
    ExtractTemp::$dirs[] = $base;

    expect(CheckReport::build($base, 'tests/Arch/configuration.php', null, 'full', new DateTimeImmutable('2026-10-28'), TreeCatalog::shipped())['violations'][0]['hint'])
        ->toStartWith('Run php artisan standards:install to scaffold tests/Arch/configuration.php');
});

test('extraction moves the inline Configuration with its imports and variables, and the gate and the Arch suite then report the same drift', function () {
    $base = inlineApp();
    $extractor = new Extractor($base);
    $plan = $extractor->plan();

    expect($plan['carried'])->toBe(['$fixture', '$namespace'])
        ->and(is_file($base.'/tests/Arch/configuration.php'))->toBeFalse('planning writes nothing');

    $extractor->apply($plan);
    $configuration = (string) file_get_contents($base.'/tests/Arch/configuration.php');
    $test = (string) file_get_contents($base.'/tests/Arch/ConformanceTest.php');

    expect($configuration)->toContain('use Tey\Standards\Configuration;')->toContain('$namespace = \'FixtureBad\';')->toContain("return new Configuration(\n")
        ->and($test)->toContain("\$preset ??= new Conformance(require __DIR__.'/configuration.php');")
        ->and($test)->not->toContain('new Configuration(');

    // Parity, rule by rule over the rules the gate runs (it skips optional rules not switched on, and the CI rule unless enforced):
    // the gate before (the inline Configuration, which is the Bad fixture's), the gate after (reading the extracted file), and the
    // Arch suite after (the Configuration its rewritten test requires) report the same findings.
    $today = new DateTimeImmutable('2026-10-28');
    $report = CheckReport::build($base, 'tests/Arch/configuration.php', null, 'full', $today, TreeCatalog::shipped(), autoload: dirname(__DIR__).'/vendor/autoload.php');
    $rows = fn (array $violations): array => array_values(array_map(fn (array $v): array => [(string) $v['rule'], $v['target'], $v['check']], $violations));
    $sorted = function (array $rows): array {
        sort($rows);

        return $rows;
    };
    $before = $sorted($rows((new Gate(badConfiguration(), $today, TreeCatalog::shipped(), 14, dirname(__DIR__).'/vendor/autoload.php'))->run()['violations']));
    $after = $sorted($rows($report['violations']));
    $pest = new Conformance(requiredConfiguration($base));
    $asserted = [];

    foreach (array_keys(Conformance::TESTS) as $rule) {
        if (! in_array((string) $rule, $report['skipped'], true)) {
            foreach ($pest->violations((string) $rule) as $violation) {
                $asserted[] = [(string) $rule, $violation['target'], $violation['check']];
            }
        }
    }

    expect($report['exit_code'])->toBe(1)
        ->and($after)->not->toBe([])
        ->and($after)->toBe($before)
        ->and($sorted($asserted))->toBe($after)
        ->and(requiredConfiguration($base))->toEqual(badConfiguration());
});

test('a dry run plans the same files and writes nothing; a second extraction refuses, never overwriting', function () {
    $base = inlineApp();
    $before = (string) file_get_contents($base.'/tests/Arch/ConformanceTest.php');
    $extractor = new Extractor($base);
    $extractor->plan();

    expect((string) file_get_contents($base.'/tests/Arch/ConformanceTest.php'))->toBe($before)
        ->and(is_file($base.'/tests/Arch/configuration.php'))->toBeFalse();

    $extractor->apply($extractor->plan());
    $written = (string) file_get_contents($base.'/tests/Arch/configuration.php');

    expect(fn () => $extractor->plan())->toThrow(InvalidArgumentException::class, 'tests/Arch/configuration.php already exists')
        ->and((string) file_get_contents($base.'/tests/Arch/configuration.php'))->toBe($written);
});

test('extraction refuses what it cannot move safely, with the reason, and writes nothing', function (string $tail, string $message) {
    $base = inlineApp($tail);
    $before = (string) file_get_contents($base.'/tests/Arch/ConformanceTest.php');

    expect(fn () => (new Extractor($base))->plan())->toThrow(InvalidArgumentException::class, $message)
        ->and((string) file_get_contents($base.'/tests/Arch/ConformanceTest.php'))->toBe($before)
        ->and(is_file($base.'/tests/Arch/configuration.php'))->toBeFalse();
})->with([
    'a variable set inside the closure' => ["\n            allowlist: \$rule === '1' ? [] : [],", 'reads $rule, which is not a top-level assignment'],
    'a second inline Configuration' => ["\n            allowlist: [new Configuration(base: '/x')],", 'builds more than one Configuration inline'],
    '$this' => ["\n            allowlist: \$this->allowlist,", 'uses $this'],
]);

test('nothing to extract is refused with the scaffold command named', function () {
    $base = sys_get_temp_dir().'/standards-extract-'.bin2hex(random_bytes(6));
    mkdir($base.'/tests/Arch', 0777, true);
    ExtractTemp::$dirs[] = $base;

    expect(fn () => (new Extractor($base))->plan())->toThrow(InvalidArgumentException::class, 'run php artisan standards:install to scaffold');
});

/** standards:install on an app at $base, through the command, as artisan runs it. */
function installCommand(string $base, array $options): Symfony\Component\Console\Tester\CommandTester
{
    $app = new Illuminate\Foundation\Application($base);
    $command = new Tey\Standards\Enforcement\InstallCommand;
    $command->setLaravel($app);
    $tester = new Symfony\Component\Console\Tester\CommandTester($command);
    $tester->execute($options);

    return $tester;
}

test('standards:install --extract --dry-run --json plans without writing; --extract writes; a refusal exits 2 with the reason', function () {
    $base = inlineApp();
    $before = (string) file_get_contents($base.'/tests/Arch/ConformanceTest.php');
    $dry = installCommand($base, ['--extract' => true, '--dry-run' => true, '--json' => true]);

    expect($dry->getStatusCode())->toBe(0)
        ->and(json_decode($dry->getDisplay(), true, flags: JSON_THROW_ON_ERROR))->toBe(['dry_run' => true, 'extracted' => false, 'from' => 'tests/Arch/ConformanceTest.php', 'carried' => ['$fixture', '$namespace'], 'files' => [['path' => 'tests/Arch/configuration.php', 'action' => 'created'], ['path' => 'tests/Arch/ConformanceTest.php', 'action' => 'modified']]])
        ->and((string) file_get_contents($base.'/tests/Arch/ConformanceTest.php'))->toBe($before)
        ->and(is_file($base.'/tests/Arch/configuration.php'))->toBeFalse();

    $run = installCommand($base, ['--extract' => true]);

    expect($run->getStatusCode())->toBe(0)
        ->and($run->getDisplay())->toContain('✓ tests/Arch/configuration.php (created)')->toContain('✓ tests/Arch/ConformanceTest.php (modified)')
        ->and(is_file($base.'/tests/Arch/configuration.php'))->toBeTrue();

    $again = installCommand($base, ['--extract' => true, '--json' => true]);

    expect($again->getStatusCode())->toBe(2)
        ->and(json_decode($again->getDisplay(), true, flags: JSON_THROW_ON_ERROR)['error'])->toContain('already exists');
});

/** Every file under $base with its bytes, so a dry run can be shown to write nothing (no files, no directories). */
function treeSnapshot(string $base): array
{
    $tree = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $path => $file) {
        $tree[str_replace('\\', '/', substr($path, strlen($base)))] = $file->isDir() ? 'dir' : (string) file_get_contents($path);
    }

    ksort($tree);

    return $tree;
}

test('standards:install --dry-run (without --extract) plans the whole install and writes 0 files; the real run writes exactly that plan', function () {
    $base = sys_get_temp_dir().'/standards-install-'.bin2hex(random_bytes(6));
    mkdir($base, 0777, true);
    file_put_contents($base.'/composer.json', json_encode(['require' => ['laravel/framework' => '^13'], 'require-dev' => ['laravel/boost' => '^2'], 'autoload' => ['psr-4' => ['App\\' => 'app/']]]));
    file_put_contents($base.'/AGENTS.md', "# App\n");
    mkdir($base.'/app');
    ExtractTemp::$dirs[] = $base;
    $before = treeSnapshot($base);

    $dry = installCommand($base, ['--dry-run' => true, '--json' => true]);
    $plan = json_decode($dry->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

    expect($dry->getStatusCode())->toBe(0)
        ->and(treeSnapshot($base))->toBe($before)
        ->and($plan['dry_run'])->toBeTrue()
        ->and($plan['installed'])->toBeFalse()
        ->and(array_column($plan['files'], 'action', 'path'))->toMatchArray([
            'tests/Arch/configuration.php' => 'created',
            'tests/Arch/ConformanceTest.php' => 'created',
            'AGENTS.md' => 'modified',
            '.claude/settings.json' => 'created',
            'boost.json' => 'created',
        ])
        ->and(array_filter(array_column($plan['files'], 'path'), fn (string $path): bool => str_starts_with($path, '.ai/skills/')))->not->toBeEmpty();

    $text = installCommand($base, ['--dry-run' => true]);

    expect($text->getDisplay())->toContain('· AGENTS.md (modified)')->toContain('Dry run: nothing written.')->not->toContain('written;')->not->toContain('✓')
        ->and(treeSnapshot($base))->toBe($before);

    $run = installCommand($base, ['--json' => true]);

    expect(json_decode($run->getDisplay(), true, flags: JSON_THROW_ON_ERROR)['files'])->toBe($plan['files'])
        ->and(is_file($base.'/tests/Arch/configuration.php'))->toBeTrue();
});
