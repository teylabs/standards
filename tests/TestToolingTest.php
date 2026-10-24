<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\Process\Process;
use Tey\Standards\Checks\Boost;
use Tey\Standards\Checks\PestOnly;
use Tey\Standards\Checks\Tia;
use Tey\Standards\Configuration;
use Tey\Standards\Conformance;

/** @param list<array{rule: string, target: string, check: string, reason: string, date: string}> $allowlist */
function toolingConfiguration(string $kind, array $allowlist = []): Configuration
{
    return new Configuration(
        base: dirname(__DIR__).'/fixtures/'.$kind,
        classRoots: [], sourceRoots: [], uiRoots: [], uiNamespaces: [], hostNamespaces: [],
        controllerRoots: [], routeRoots: [], controllerSuffix: 'Controller', routeDirectory: 'routes',
        viewModelRoots: [], viewModelBases: [], typedViewModel: 'ViewModel', viewModelSuffix: 'ViewModel',
        pageRoots: [], pageScaffolds: [], placements: [], retired: [], discoveryRoots: [],
        developmentNamespaces: [], safeAttributes: [], mediaTrait: 'Media', mediaInterface: 'HasMedia',
        allowlist: $allowlist,
    );
}

beforeEach(function () {
    $this->toolingBase = sys_get_temp_dir().'/standards-tooling-'.bin2hex(random_bytes(6));
    (new Filesystem)->copyDirectory(dirname(__DIR__).'/fixtures/Good', $this->toolingBase);
    $this->toolingConfig = new Configuration(...array_replace(get_object_vars(toolingConfiguration('Good')), ['base' => $this->toolingBase]));
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->toolingBase);
});

test('pest-only finds direct, aliased and indirect test classes without loading test code', function (string $code) {
    file_put_contents($this->toolingBase.'/tests/PostsTest.php', '<?php '.$code);
    expect((new PestOnly($this->toolingConfig))->violations())->toHaveCount(1)
        ->and((new PestOnly($this->toolingConfig))->violations()[0]['target'])->toBe('tests/PostsTest.php');
})->with([
    'direct PHPUnit' => 'class PostsTest extends \\PHPUnit\\Framework\\TestCase { public function test_posts(): void {} }',
    'aliased Laravel' => 'use Illuminate\\Foundation\\Testing\\TestCase as Base; class PostsTest extends Base { public function test_posts(): void {} }',
    'indirect with attribute alias' => 'use PHPUnit\\Framework\\Attributes\\Test as Check; class PostsTest extends \\Tests\\TestCase { #[Check] public function posts(): void {} }',
]);

test('pest-only accepts configured bases and referenced Case bases but catches an unreferenced test Case', function () {
    file_put_contents($this->toolingBase.'/tests/BrowserCase.php', '<?php namespace Tests; class BrowserCase extends TestCase { public function test_shared(): void {} }');
    file_put_contents($this->toolingBase.'/tests/Pest.php', '<?php pest()->extend(Tests\\BrowserCase::class)->in("Browser");');
    expect((new PestOnly($this->toolingConfig))->violations())->toBe([]);
    file_put_contents($this->toolingBase.'/tests/Pest.php', '<?php');
    expect((new PestOnly($this->toolingConfig))->violations())->toHaveCount(1);
    $config = new Configuration(...array_replace(get_object_vars($this->toolingConfig), ['testBasePaths' => ['tests/TestCase.php', 'tests/BrowserCase.php']]));
    expect((new PestOnly($config))->violations())->toBe([]);
});

test('pest-only ignores helpers, comments and unrelated classes', function () {
    file_put_contents($this->toolingBase.'/tests/Helpers.php', '<?php /* class Fake extends PHPUnit\\Framework\\TestCase {} */ class Helper { public function test_format(): void {} } class Support extends \\Tests\\TestCase { public function makeOrder(): void {} }');
    expect((new PestOnly($this->toolingConfig))->violations())->toBe([]);
});

test('TIA rejects missing flags, bypasses, comments, zero exits and conditional exits', function (string $search, string $replacement) {
    $path = $this->toolingBase.'/tests/Pest.php';
    file_put_contents($path, str_replace($search, $replacement, file_get_contents($path)));
    expect((new Tia($this->toolingConfig))->violations())->toHaveCount(1);
})->with([
    'missing flag' => ["'--no-tia'", "'--other'"],
    'missing CI bypass' => ["getenv('CI') === false && ", ''],
    'missing worker bypass' => ["getenv('PARATEST') === false && ", ''],
    'missing replay bypass' => ["getenv('PEST_TIA') === false && ", ''],
    'zero exit' => ['exit(1)', 'exit(0)'],
    'wrapped exit' => ['exit(1)', 'exit(256)'],
    'unused argv' => ["\$_SERVER['argv']", "\$_SERVER['other']"],
    'wrong arguments' => [', $argv, true)', ', [], true)'],
    'loose flag matching' => [', $argv, true)', ', $argv, false)'],
    'comment only' => ['exit(1);', '// exit(1);'],
    'dead exit' => ['exit(1);', 'if (false) { exit(1); }'],
    'wrong boolean' => [' && ', ' || '],
]);

test('the copied TIA guard refuses an implicit local run and permits every explicit bypass', function (array $arguments, array $environment, int $exit) {
    $source = file_get_contents($this->toolingBase.'/tests/Pest.php');
    $source = substr($source, 0, strpos($source, 'pest()->'));
    $path = $this->toolingBase.'/guard.php';
    file_put_contents($path, $source);
    $process = new Process([PHP_BINARY, $path, ...$arguments], env: array_replace(['CI' => false, 'PARATEST' => false, 'PEST_TIA' => false], $environment));
    expect($process->run())->toBe($exit);
})->with([
    'local' => [[], [], 1],
    'TIA' => [['--tia'], [], 0],
    'explicit full' => [['--no-tia'], [], 0],
    'CI' => [[], ['CI' => 'true'], 0],
    'parallel worker' => [[], ['PARATEST' => '1'], 0],
    'TIA replay' => [[], ['PEST_TIA' => '1'], 0],
]);

test('TIA reports a missing Pest bootstrap', function () {
    unlink($this->toolingBase.'/tests/Pest.php');
    expect((new Tia($this->toolingConfig))->violations()[0]['target'])->toBe('tests/Pest.php');
});

test('Boost reports one dependency prerequisite when Boost is absent', function () {
    file_put_contents($this->toolingBase.'/composer.json', '{}');
    expect((new Boost($this->toolingConfig))->violations())->toBe([
        ['target' => 'composer.json', 'check' => 'laravel/boost must be declared in require-dev'],
    ]);
});

test('Boost rejects invalid or missing configuration objects', function (string $contents) {
    file_put_contents($this->toolingBase.'/boost.json', $contents);
    expect((new Boost($this->toolingConfig))->violations())->toBe([
        ['target' => 'boost.json', 'check' => 'Boost configuration must be a readable JSON object'],
    ]);
})->with(['{broken', '[]', 'null', '']);

test('Boost accepts either agent file and configured skill roots and reports absent stack skills', function () {
    rename($this->toolingBase.'/AGENTS.md', $this->toolingBase.'/CLAUDE.md');
    (new Filesystem)->ensureDirectoryExists($this->toolingBase.'/.agents/skills');
    rename($this->toolingBase.'/.claude/skills/pest-testing', $this->toolingBase.'/.agents/skills/pest-testing');
    expect((new Boost($this->toolingConfig))->violations())->toBe([]);
    unlink($this->toolingBase.'/.agents/skills/pest-testing/SKILL.md');
    expect((new Boost($this->toolingConfig))->violations())->toBe([
        ['target' => 'boost.json', 'check' => 'missing Boost skill pest-testing for pestphp/pest'],
    ]);
});

test('Boost checks npm stack skills and honours the configured package map', function () {
    file_put_contents($this->toolingBase.'/package.json', '{"devDependencies":{"tailwindcss":"^4.0"}}');
    expect(array_column((new Boost($this->toolingConfig))->violations(), 'check'))->toContain('missing Boost skill tailwindcss-development for tailwindcss');
    $config = new Configuration(...array_replace(get_object_vars($this->toolingConfig), ['boostSkills' => []]));
    expect((new Boost($config))->violations())->toBe([]);
});

test('Boost reports missing guidelines and a missing generated block', function () {
    (new Filesystem)->deleteDirectory($this->toolingBase.'/.ai/guidelines');
    file_put_contents($this->toolingBase.'/AGENTS.md', '<laravel-boost-guidelines></laravel-boost-guidelines>');
    expect(array_column((new Boost($this->toolingConfig))->violations(), 'target'))->toBe(['.ai/guidelines', 'AGENTS.md']);
});

test('tooling rules honour exact ratchets and reject stale entries after a fix', function (string $rule) {
    $bad = new Conformance(toolingConfiguration('Bad'));
    $entry = ['rule' => $rule, ...$bad->violations($rule)[0], 'reason' => 'Existing fixture adoption debt.', 'date' => '2026-10-24'];
    (new Conformance(toolingConfiguration('Bad', [$entry])))->assert($rule);
    expect(fn () => (new Conformance(toolingConfiguration('Good', [$entry])))->assert($rule))->toThrow(AssertionFailedError::class, 'stale exception');
})->with(['pest-only', 'tia', 'boost']);
