<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Builds a copy of fixtures/App with the fixtures/Parity overlay and a vendor
 * tree that points at this package's dependencies. Pest needs its own bin
 * under the app's vendor so its root path is the app, as in a real install.
 */
function parityApp(): string
{
    $package = dirname(__DIR__);
    $base = sys_get_temp_dir().'/standards-parity-'.bin2hex(random_bytes(6));
    $files = new Filesystem;
    $files->copyDirectory($package.'/fixtures/App', $base);
    $files->copyDirectory($package.'/fixtures/Parity', $base);
    mkdir($base.'/vendor/pestphp/pest', 0777, true);

    foreach (glob($package.'/vendor/*') ?: [] as $entry) {
        if (! in_array(basename($entry), ['autoload.php', 'pestphp'], true)) {
            symlink($entry, $base.'/vendor/'.basename($entry));
        }
    }

    foreach (glob($package.'/vendor/pestphp/*') ?: [] as $entry) {
        if (basename($entry) !== 'pest') {
            symlink($entry, $base.'/vendor/pestphp/'.basename($entry));
        }
    }

    foreach (glob($package.'/vendor/pestphp/pest/*') ?: [] as $entry) {
        if (basename($entry) !== 'bin') {
            symlink($entry, $base.'/vendor/pestphp/pest/'.basename($entry));
        }
    }

    $files->copyDirectory($package.'/vendor/pestphp/pest/bin', $base.'/vendor/pestphp/pest/bin');
    // Pest's arch plugin evaluates the second-to-last line of the app's autoload.php to find its loader.
    file_put_contents($base.'/vendor/autoload.php', "<?php\n\nreturn (function () { \$loader = require ".var_export($package.'/vendor/autoload.php', true)."; \$loader->addPsr4('App\\\\', dirname(__DIR__).'/app/'); \$loader->addPsr4('Tests\\\\', dirname(__DIR__).'/tests/'); return \$loader; })();\n");

    return $base;
}

test('standards:check and the Pest suite report the same findings for the same app', function () {
    $base = parityApp();

    try {
        $gate = new Process([PHP_BINARY, $base.'/gate.php'], $base);
        $gate->mustRun();
        $report = json_decode($gate->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        $out = $base.'/pest-findings.json';
        $pest = new Process([PHP_BINARY, $base.'/vendor/pestphp/pest/bin/pest', '--no-tia', 'tests/Arch/ParityTest.php'], $base, ['STANDARDS_PARITY_OUT' => $out, 'CI' => false, 'PARATEST' => false, 'PEST_TIA' => false]);
        $pest->run();
        expect(is_file($out))->toBeTrue($pest->getOutput().$pest->getErrorOutput());

        $viaPest = [];

        foreach (json_decode((string) file_get_contents($out), true, flags: JSON_THROW_ON_ERROR) as $rule => $violations) {
            if (! in_array((string) $rule, $report['skipped'], true)) {
                foreach ($violations as $violation) {
                    $viaPest[] = [(string) $rule, $violation['target'], $violation['check']];
                }
            }
        }

        $viaCheck = array_map(fn (array $finding): array => [$finding['rule'], $finding['target'], $finding['check']], $report['violations']);
        sort($viaPest);
        sort($viaCheck);

        expect($viaCheck)->toBe($viaPest)
            ->and($viaCheck)->toContain(
                ['preset laravel', 'App\\Console\\FirstOwnerCommand', "Expecting 'app/Console/FirstOwnerCommand.php' not to extend 'Illuminate\\Console\\Command'."],
                ['preset laravel', 'App\\Support\\TypeScriptProvider', "Expecting 'app/Support/TypeScriptProvider.php' not to extend 'Illuminate\\Support\\ServiceProvider'."],
            );
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }
});
