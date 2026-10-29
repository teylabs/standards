<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Tey\Standards\Conformance;
use Tey\Standards\Configuration;
use Tey\Standards\Ratchet;

/** Rule `versioned-writes` on a tree: only base and the source roots matter here. */
function versionedConfiguration(string $base, array $options = []): Configuration
{
    return new Configuration(...[
        'base' => $base, 'classRoots' => ['src' => 'App'], 'sourceRoots' => ['src'], 'uiRoots' => [], 'uiNamespaces' => [], 'hostNamespaces' => [], 'controllerRoots' => [], 'routeRoots' => [],
        'controllerSuffix' => 'Controller', 'routeDirectory' => 'routes', 'viewModelRoots' => [], 'viewModelBases' => [], 'typedViewModel' => '', 'viewModelSuffix' => 'ViewModel', 'pageRoots' => [],
        'pageScaffolds' => [], 'placements' => [], 'retired' => [], 'discoveryRoots' => [], 'developmentNamespaces' => [], 'safeAttributes' => [], 'mediaTrait' => '', 'mediaInterface' => '', ...$options,
    ]);
}

/** A scratch app: a versioned Post model (its own trait), and one class whose method body is $body. */
function versionedApp(string $body, string $trait = 'Versioned', string $model = ''): string
{
    $base = sys_get_temp_dir().'/standards-versioned-'.bin2hex(random_bytes(6));
    mkdir($base.'/src/Models/Concerns', 0777, true);
    file_put_contents($base.'/src/Models/Concerns/'.$trait.'.php', "<?php\n\nnamespace App\\Models\\Concerns;\n\ntrait {$trait}\n{\n}\n");
    file_put_contents($base.'/src/Models/Post.php', $model !== '' ? $model : "<?php\n\nnamespace App\\Models;\n\nuse App\\Models\\Concerns\\{$trait};\nuse Illuminate\\Database\\Eloquent\\Model;\n\nfinal class Post extends Model\n{\n    use {$trait};\n}\n");
    file_put_contents($base.'/src/Writer.php', "<?php\n\nnamespace App;\n\nuse App\\Models\\Post;\nuse Illuminate\\Support\\Facades\\DB;\n\nfinal class Writer\n{\n    public function run(int \$id): void\n    {\n{$body}\n    }\n}\n");
    VersionedTemp::$dirs[] = $base;

    return $base;
}

final class VersionedTemp
{
    /** @var list<string> */
    public static array $dirs = [];
}

afterEach(function () {
    foreach (VersionedTemp::$dirs as $base) {
        (new Filesystem)->deleteDirectory($base);
    }
    VersionedTemp::$dirs = [];
});

test('the seeded fixture reports each builder write that skips the bump, with its line and a fix; the clean control and the instance write do not', function () {
    $bad = (new Conformance(versionedConfiguration(dirname(__DIR__).'/fixtures/Bad', ['classRoots' => ['src' => 'FixtureBad']])))->violations('versioned-writes');

    expect(array_map(fn (array $v): array => [$v['line'], $v['check']], $bad))->toBe([
        [15, 'FixtureBad\\Models\\Document builder update() skips the version bump'],
        [16, 'FixtureBad\\Models\\Document builder upsert() skips the version bump'],
        [17, 'FixtureBad\\Models\\Document builder increment() skips the version bump'],
        [18, 'FixtureBad\\Models\\Document builder update() skips the version bump'],
    ])
        ->and($bad[0]['hint'])->toContain("DB::raw('version + 1')")
        ->and((new Conformance(versionedConfiguration(dirname(__DIR__).'/fixtures/Good', ['classRoots' => ['src' => 'FixtureGood']])))->violations('versioned-writes'))->toBe([]);
});

test('each write form: what bumps passes, what skips is reported', function (string $body, bool $reported) {
    $checks = array_column((new Conformance(versionedConfiguration(versionedApp($body))))->violations('versioned-writes'), 'check');

    expect($checks !== [])->toBe($reported, implode(', ', $checks));
})->with([
    'where()->update without version' => ["Post::where('id', \$id)->update(['title' => 'x']);", true],
    'where()->update with DB::raw' => ["Post::where('id', \$id)->update(['title' => 'x', 'version' => DB::raw('version + 1')]);", false],
    'query()->update with a value' => ["Post::query()->whereKey(\$id)->update(['title' => 'x', 'version' => 3]);", false],
    'upsert with an update list missing it' => ["Post::upsert([['id' => \$id, 'version' => 1]], ['id'], ['title']);", true],
    'upsert with it in the update list' => ["Post::upsert([['id' => \$id, 'title' => 'x']], ['id'], ['title', 'version']);", false],
    'upsert without an update list, values carry it' => ["Post::upsert([['id' => \$id, 'version' => 2]], ['id']);", false],
    'increment another column' => ["Post::where('id', \$id)->increment('views');", true],
    'increment another column with version in extra' => ["Post::where('id', \$id)->increment('views', 1, ['version' => DB::raw('version + 1')]);", false],
    'increment version itself' => ["Post::where('id', \$id)->increment('version');", false],
    'DB::table on its table' => ["DB::table('posts')->where('id', \$id)->update(['title' => 'x']);", true],
    'DB::table on another table' => ["DB::table('logs')->where('id', \$id)->update(['title' => 'x']);", false],
    'an instance write runs the events' => ["Post::findOrFail(\$id)->update(['title' => 'x']);", false],
    'first() then update is an instance write' => ["Post::where('id', \$id)->first()->update(['title' => 'x']);", false],
]);

test('an app with no versioned model gets nothing; Configuration::$versionedModels and $versionColumn adapt it', function () {
    $plain = versionedApp("Post::where('id', \$id)->update(['title' => 'x']);", 'Stamped');
    expect((new Conformance(versionedConfiguration($plain)))->violations('versioned-writes'))->toBe([], 'a trait not named for versioning is not detected')
        ->and((new Conformance(versionedConfiguration($plain, ['versionedModels' => ['App\\Models\\Concerns\\Stamped']])))->violations('versioned-writes'))->toHaveCount(1);

    $base = "<?php\n\nnamespace App\\Models;\n\nuse Illuminate\\Database\\Eloquent\\Model;\n\nabstract class Guarded extends Model\n{\n}\n";
    $app = versionedApp("Post::where('id', \$id)->update(['title' => 'x', 'revision' => DB::raw('revision + 1')]);", 'Unused', "<?php\n\nnamespace App\\Models;\n\nfinal class Post extends Guarded\n{\n}\n");
    file_put_contents($app.'/src/Models/Guarded.php', $base);
    $options = ['versionedModels' => ['App\\Models\\Guarded']];

    expect((new Conformance(versionedConfiguration($app, $options)))->violations('versioned-writes'))->toHaveCount(1, 'a base class counts; the default version column is not bumped')
        ->and((new Conformance(versionedConfiguration($app, [...$options, 'versionColumn' => 'revision'])))->violations('versioned-writes'))->toBe([]);
});

test('a deliberate exception is allowlisted with a reason and an expiry', function () {
    $violations = (new Conformance(versionedConfiguration(versionedApp("Post::where('id', \$id)->update(['title' => 'x']);"))))->violations('versioned-writes');
    $entry = ['rule' => 'versioned-writes', 'target' => 'src/Writer.php', 'check' => $violations[0]['check'], 'reason' => 'A migration backfill; no live guard reads these rows.', 'date' => '2026-10-29', 'expires' => '2027-01-31'];

    expect((new Ratchet([$entry]))->failures('versioned-writes', array_map(fn (array $v): array => ['target' => $v['target'], 'check' => $v['check']], $violations)))->toBe([]);
});
