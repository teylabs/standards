<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Tey\Standards\Enforcement\ChangedFiles;

/** Runs git in a directory with the developer's global config kept out. */
function changedGit(string $directory, array $arguments): string
{
    $process = new Process(['git', ...$arguments], $directory, ['GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_AUTHOR_NAME' => 'T', 'GIT_AUTHOR_EMAIL' => 't@example.test', 'GIT_COMMITTER_NAME' => 'T', 'GIT_COMMITTER_EMAIL' => 't@example.test']);
    $process->mustRun();

    return $process->getOutput();
}

beforeEach(function () {
    $this->changedRoot = sys_get_temp_dir().'/standards-changed-'.bin2hex(random_bytes(6));
    mkdir($this->changedRoot, 0777, true);
});

afterEach(fn () => (new Filesystem)->deleteDirectory($this->changedRoot));

test('--changed compares against the remote\'s default branch: a trunk named live, not a missing origin/main', function () {
    // The remote's trunk is `live`; a clone's origin/HEAD points at it.
    $origin = $this->changedRoot.'/origin';
    mkdir($origin);
    changedGit($origin, ['init', '-q', '-b', 'live']);
    file_put_contents($origin.'/a.php', "<?php\n");
    file_put_contents($origin.'/b.php', "<?php\n");
    changedGit($origin, ['add', '.']);
    changedGit($origin, ['commit', '-q', '-m', 'trunk']);
    changedGit($this->changedRoot, ['clone', '-q', $origin, 'app']);
    $app = $this->changedRoot.'/app';
    changedGit($app, ['checkout', '-q', '-b', 'feature']);
    file_put_contents($app.'/c.php', "<?php\n");
    changedGit($app, ['add', 'c.php']);
    changedGit($app, ['commit', '-q', '-m', 'feature']);

    expect(ChangedFiles::defaultRef($app))->toBe('origin/live')
        ->and(ChangedFiles::since($app, ChangedFiles::defaultRef($app)))->toBe(['c.php'], 'only the branch\'s own change, not every file');

    // No origin/HEAD: origin/main when it exists, else main.
    changedGit($app, ['remote', 'set-head', 'origin', '--delete']);
    expect(ChangedFiles::defaultRef($app))->toBe('main');
    changedGit($app, ['update-ref', 'refs/remotes/origin/main', 'HEAD']);
    expect(ChangedFiles::defaultRef($app))->toBe('origin/main');
});

test('STANDARDS_BASE_REF still overrides the default (standards.base_ref is null unless set)', function () {
    $config = require dirname(__DIR__).'/config/standards.php';

    expect($config['base_ref'])->toBe(getenv('STANDARDS_BASE_REF') === false ? null : getenv('STANDARDS_BASE_REF'));
    expect((string) file_get_contents(dirname(__DIR__).'/src/Enforcement/CheckCommand.php'))
        ->toContain("\$this->option('base') ?? \$config->get('standards.base_ref') ?? ChangedFiles::defaultRef(\$base)")
        ->toContain('fwrite(STDERR, "standards:check: --changed against {$ref}\\n");');
});
