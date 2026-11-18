<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Tey\Standards\Conformance;
use Tey\Standards\Configuration;

/** A scratch app requiring tey/kit, with files written at their paths under it. */
function flagTagsApp(array $files, bool $kit = true): Configuration
{
    $base = dirname(__DIR__).'/.phpunit.cache/flag-tags-'.bin2hex(random_bytes(5));
    foreach (['composer.json' => json_encode(['require' => $kit ? ['tey/kit' => '^0.39'] : []])] + $files as $path => $contents) {
        is_dir(dirname($base.'/'.$path)) || mkdir(dirname($base.'/'.$path), 0777, true);
        file_put_contents($base.'/'.$path, $contents);
    }
    FlagTagsTemp::$dirs[] = $base;

    return new Configuration(
        base: $base, classRoots: ['app' => 'App'], sourceRoots: ['app'], uiRoots: [], uiNamespaces: [], hostNamespaces: ['App'],
        controllerRoots: [], routeRoots: [], controllerSuffix: 'Controller', routeDirectory: 'routes',
        viewModelRoots: [], viewModelBases: [], typedViewModel: '', viewModelSuffix: 'ViewModel',
        pageRoots: [], pageScaffolds: [], placements: [], retired: [], discoveryRoots: [],
        developmentNamespaces: [], safeAttributes: [], mediaTrait: '', mediaInterface: '',
    );
}

final class FlagTagsTemp
{
    /** @var list<string> */
    public static array $dirs = [];
}

afterEach(function () {
    foreach (FlagTagsTemp::$dirs as $directory) {
        (new Filesystem)->deleteDirectory($directory);
    }
    FlagTagsTemp::$dirs = [];
});

test('flags.via-api warns on spatie\'s tag writers called with the flag type, and not on HasFlags or people\'s tags', function () {
    $config = flagTagsApp(['app/Actions/MarkCase.php' => <<<'PHP'
<?php

namespace App\Actions;

use Tey\Kit\Features\Workspaces\Flags\Flags;

final class MarkCase
{
    public function handle($case): void
    {
        $case->attachTag('unpaid', 'flag');
        $case->syncTagsWithType(['stalled'], Flags::TYPE);
        $case->flag('possible-duplicate', reason: 'Looks like A-1');
        $case->unflag('stalled');
        $case->attachTag('VIP');
        $case->attachTags(['Rush'], 'priority');
    }
}
PHP]);

    $violations = (new Conformance($config))->violations('flags.via-api');

    expect(array_column($violations, 'check'))->toBe(['attachTag() writes a flag-typed tag directly', 'syncTagsWithType() writes a flag-typed tag directly'])
        ->and(array_column($violations, 'line'))->toBe([11, 12])
        ->and(array_unique(array_column($violations, 'advisory')))->toBe([true]);
});

test('flags.via-api is quiet in an app without tey/kit', function () {
    $config = flagTagsApp(['app/Tagger.php' => "<?php\n\nnamespace App;\n\nfinal class Tagger { public function run(\$m) { \$m->attachTag('x', 'flag'); } }\n"], kit: false);

    expect((new Conformance($config))->violations('flags.via-api'))->toBe([]);
});

test('ui.tag-list warns on a hand-rolled row of Badges, not on TagList or a single Badge', function () {
    $config = flagTagsApp([
        'resources/js/pages/Cases/Index/Page.vue' => "<script setup lang=\"ts\">\nimport { Badge } from '@kit/ui';\n</script>\n<template>\n    <div>\n        <span v-for=\"tag in row.tags\" :key=\"tag.key\">\n            <Badge :tone=\"tag.tone\">{{ tag.label }}</Badge>\n        </span>\n    </div>\n</template>\n",
        'resources/js/pages/Cases/Show/Page.vue' => "<script setup lang=\"ts\">\nimport { Badge } from '@kit/ui';\n</script>\n<template>\n    <Badge v-for=\"flag in flags\" :key=\"flag\">{{ flag }}</Badge>\n</template>\n",
        'resources/js/pages/Cases/Good/Page.vue' => "<script setup lang=\"ts\">\nimport { Badge, TagList } from '@kit/ui';\n</script>\n<template>\n    <TagList :tags=\"row.tags\" label=\"Tags\" />\n    <Badge tone=\"info\">Draft</Badge>\n    <li v-for=\"item in items\" :key=\"item\">{{ item }}</li>\n</template>\n",
    ]);

    $violations = (new Conformance($config))->violations('ui.tag-list');
    $targets = array_map(fn (array $violation): string => basename(dirname($violation['target'], 2)).'/'.basename(dirname($violation['target'])), $violations);
    sort($targets);

    expect($targets)->toBe(['Cases/Index', 'Cases/Show'])
        ->and(array_unique(array_column($violations, 'check')))->toBe(["a hand-rolled row of Badges: use the kit's TagList"]);
});
