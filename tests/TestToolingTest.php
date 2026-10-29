<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\Process\Process;
use Tey\Standards\Checks\Boost;
use Tey\Standards\Checks\PestOnly;
use Tey\Standards\Checks\PhpTag;
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
    'missing CI bypass' => ["(getenv('CI') === false || getenv('CI') === '') && ", ''],
    'half CI check' => ["(getenv('CI') === false || getenv('CI') === '')", "(getenv('CI') === false || getenv('PARATEST') === '')"],
    'CI emptiness alone' => ["(getenv('CI') === false || getenv('CI') === '')", "getenv('CI') === ''"],
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

test('TIA accepts the non-empty CI check, its negated form and the older unset-only check', function (string $ci) {
    $path = $this->toolingBase.'/tests/Pest.php';
    file_put_contents($path, str_replace("(getenv('CI') === false || getenv('CI') === '')", $ci, file_get_contents($path)));
    expect((new Tia($this->toolingConfig))->violations())->toBe([]);
})->with([
    'recommended' => ["(getenv('CI') === false || getenv('CI') === '')"],
    'reversed' => ["('' === getenv('CI') || false === getenv('CI'))"],
    'negated' => ["! (getenv('CI') !== false && getenv('CI') !== '')"],
    'unset only' => ["getenv('CI') === false"],
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
    'empty CI is not CI' => [[], ['CI' => ''], 1],
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
    rename($this->toolingBase.'/.claude/skills/testing-best-practices', $this->toolingBase.'/.agents/skills/testing-best-practices');
    expect((new Boost($this->toolingConfig))->violations())->toBe([]);
    file_put_contents($this->toolingBase.'/.agents/skills/testing-best-practices/SKILL.md', " \n");
    expect((new Boost($this->toolingConfig))->violations())->toBe([
        ['target' => 'boost.json', 'check' => 'missing Boost skill testing-best-practices for pestphp/pest'],
    ]);
    unlink($this->toolingBase.'/.agents/skills/testing-best-practices/SKILL.md');
    expect((new Boost($this->toolingConfig))->violations())->toBe([
        ['target' => 'boost.json', 'check' => 'missing Boost skill testing-best-practices for pestphp/pest', 'hint' => 'Commit the skill, or generate it per checkout: list it under "skills" in boost.json, or run boost:update from a composer.json post-install-cmd, post-update-cmd or post-autoload-dump script.'],
    ]);
});

test('Boost accepts absent skills that boost.json tracks or a Composer hook regenerates per checkout', function (string $file, array $merge) {
    (new Filesystem)->deleteDirectory($this->toolingBase.'/.claude/skills/testing-best-practices');
    $path = $this->toolingBase.'/'.$file;
    file_put_contents($path, json_encode(array_replace(json_decode(file_get_contents($path), true), $merge)));
    expect((new Boost($this->toolingConfig))->violations())->toBe([]);
})->with([
    'boost.json skills' => ['boost.json', ['skills' => ['testing-best-practices']]],
    'post-update-cmd string' => ['composer.json', ['scripts' => ['post-update-cmd' => '@php artisan boost:update --ansi']]],
    'post-autoload-dump list' => ['composer.json', ['scripts' => ['post-autoload-dump' => ['@php artisan package:discover', '@php artisan boost:install']]]],
]);

test('Boost still reports absent skills that nothing regenerates', function () {
    (new Filesystem)->deleteDirectory($this->toolingBase.'/.claude/skills/testing-best-practices');
    $boost = json_decode(file_get_contents($this->toolingBase.'/boost.json'), true);
    file_put_contents($this->toolingBase.'/boost.json', json_encode($boost + ['skills' => ['laravel-best-practices']]));
    $composer = json_decode(file_get_contents($this->toolingBase.'/composer.json'), true);
    file_put_contents($this->toolingBase.'/composer.json', json_encode($composer + ['scripts' => ['test' => 'boost:update', 'post-update-cmd' => '@php artisan boost:updated']]));
    expect((new Boost($this->toolingConfig))->violations())->toBe([
        ['target' => 'boost.json', 'check' => 'missing Boost skill testing-best-practices for pestphp/pest', 'hint' => 'Commit the skill, or generate it per checkout: list it under "skills" in boost.json, or run boost:update from a composer.json post-install-cmd, post-update-cmd or post-autoload-dump script.'],
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

test('php-tag fails a PHP file without the opening tag, which PHP would print instead of run, and passes a normal one', function () {
    mkdir($this->toolingBase.'/tests/Feature', 0777, true);
    file_put_contents($this->toolingBase.'/tests/Feature/FragmentTest.php', "it('never runs', function () {\n    expect(true)->toBeTrue();\n});\n");
    file_put_contents($this->toolingBase.'/tests/Feature/NormalTest.php', "<?php\n\nit('runs', fn () => expect(true)->toBeTrue());\n");
    expect((new PhpTag($this->toolingConfig))->violations())->toBe([['target' => 'tests/Feature/FragmentTest.php', 'check' => 'does not open with <?php: PHP prints it as text and runs nothing']]);

    unlink($this->toolingBase.'/tests/Feature/FragmentTest.php');
    expect((new PhpTag($this->toolingConfig))->violations())->toBe([]);

    // Templates open with markup, not the tag, and are not checked: Blade anywhere (a module's views live under a source root), plain PHP under resources/views.
    $views = $this->toolingBase.'/app/Modules/Billing/resources/views/invoices';
    mkdir($views, 0777, true);
    file_put_contents($views.'/show.blade.php', "<x-layout>\n    <h1>{{ \$invoice->number }}</h1>\n</x-layout>\n");
    file_put_contents($views.'/receipt.php', "<p>Paid <?= e(\$amount) ?></p>\n");
    mkdir($this->toolingBase.'/app/Modules/Billing/Http', 0777, true);
    file_put_contents($this->toolingBase.'/tests/Feature/mail.blade.php', "<p>Hello</p>\n");
    $modules = new Configuration(...array_replace(get_object_vars($this->toolingConfig), ['sourceRoots' => ['app']]));
    expect((new PhpTag($modules))->violations())->toBe([]);
    // A PHP class beside them is still checked.
    file_put_contents($this->toolingBase.'/app/Modules/Billing/Http/InvoiceController.php', "namespace App\\Modules\\Billing\\Http;\n");
    expect(array_column((new PhpTag($modules))->violations(), 'target'))->toBe(['app/Modules/Billing/Http/InvoiceController.php']);
    // The Good fixture's own module view, under its `src` source root, passes as it is.
    $good = new Configuration(...array_replace(get_object_vars($this->toolingConfig), ['sourceRoots' => ['src']]));
    expect(is_file($this->toolingBase.'/src/Modules/Billing/resources/views/show.blade.php'))->toBeTrue()->and((new PhpTag($good))->violations())->toBe([]);
    expect(PhpTag::template('resources/views/welcome.php'))->toBeTrue()->and(PhpTag::template('app/Views/Composer.php'))->toBeFalse()->and(PhpTag::template('lang/en/auth.php'))->toBeFalse();
    // Nothing to check under a test root that does not exist yet.
    $config = new Configuration(...array_replace(get_object_vars($this->toolingConfig), ['testRoots' => ['tests', 'spec']]));
    expect((new PhpTag($config))->violations())->toBe([]);
});

test('php-tag allows only what PHP allows before the tag: a byte-order mark, then a shebang line', function (string $contents, bool $opens) {
    expect(PhpTag::opens($contents))->toBe($opens);
})->with([
    'plain' => ["<?php\n", true],
    'upper case' => ["<?PHP\nreturn 1;\n", true],
    'tag at the end of the file' => ['<?php', true],
    'byte-order mark' => ["\xEF\xBB\xBF<?php\n", true],
    'shebang' => ["#!/usr/bin/env php\n<?php\n", true],
    'byte-order mark and shebang' => ["\xEF\xBB\xBF#!/usr/bin/env php\n<?php\n", true],
    'no tag' => ["it('x', fn () => true);\n", false],
    'empty' => ['', false],
    'leading blank line' => ["\n<?php\n", false],
    'leading space' => [" <?php\n", false],
    'short tag' => ["<?\necho 1;\n", false],
    'short echo' => ["<?= 'x' ?>\n", false],
    'tag glued to code' => ["<?phpecho 1;\n", false],
    'shebang without a tag' => ["#!/usr/bin/env php\necho 1;\n", false],
]);

test('tooling rules honour exact ratchets and reject stale entries after a fix', function (string $rule) {
    $bad = new Conformance(toolingConfiguration('Bad'));
    $entry = ['rule' => $rule, ...$bad->violations($rule)[0], 'reason' => 'Existing fixture adoption debt.', 'date' => '2026-10-24', 'expires' => '2099-12-31'];
    (new Conformance(toolingConfiguration('Bad', [$entry])))->assert($rule);
    expect(fn () => (new Conformance(toolingConfiguration('Good', [$entry])))->assert($rule))->toThrow(AssertionFailedError::class, 'stale exception');
})->with(['pest-only', 'php-tag', 'tia', 'boost']);

test('default Boost skills use the names Boost 2.10 ships', function () {
    $skills = (new ReflectionClass(Configuration::class))->getConstructor()->getParameters();
    $defaults = array_values(array_filter($skills, fn (ReflectionParameter $p): bool => $p->getName() === 'boostSkills'))[0]->getDefaultValue();

    expect($defaults['pestphp/pest'])->toBe(['testing-best-practices'])
        ->and(array_merge(...array_values($defaults)))->not->toContain('pest-testing')
        ->and($defaults)->toMatchArray([
            'laravel/framework' => ['laravel-best-practices'],
            'laravel/folio' => ['folio-routing'],
            'laravel/wayfinder' => ['wayfinder-development'],
            '@inertiajs/vue3' => ['inertia-vue-development'],
            'tailwindcss' => ['tailwindcss-development'],
        ]);
});

test('Boost requires agents and packages in boost.json, since boost:install without packages deletes project skills', function (string $contents) {
    file_put_contents($this->toolingBase.'/boost.json', $contents);
    expect((new Boost($this->toolingConfig))->violations())->toBe([
        ['target' => 'boost.json', 'check' => 'boost.json must list "agents" and "packages" before boost:install or boost:update (run php artisan standards:install to infer them)'],
    ]);
})->with([
    'empty' => '{}',
    'no packages' => '{"agents": ["claude_code"]}',
    'no agents' => '{"packages": []}',
    'empty agents' => '{"agents": [], "packages": []}',
]);

test('Boost requires the Inertia Vue and Tailwind skills only for majors Boost 2.10 ships them for', function (string $inertia, string $tailwind, array $expected) {
    file_put_contents($this->toolingBase.'/package.json', json_encode(['dependencies' => ['@inertiajs/vue3' => $inertia, 'tailwindcss' => $tailwind]]));
    $checks = array_column((new Boost($this->toolingConfig))->violations(), 'check');
    expect(array_values(array_filter($checks, fn (string $check): bool => str_contains($check, '@inertiajs') || str_contains($check, 'tailwindcss'))))->toBe($expected);
})->with([
    'Inertia 3, Tailwind 5' => ['^3.0', '^5.0', []],
    'Inertia 2, Tailwind 4' => ['^2.0', '^4.0', ['missing Boost skill inertia-vue-development for @inertiajs/vue3', 'missing Boost skill tailwindcss-development for tailwindcss']],
    'unreadable constraints still require' => ['latest', 'next', ['missing Boost skill inertia-vue-development for @inertiajs/vue3', 'missing Boost skill tailwindcss-development for tailwindcss']],
]);
