<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Tey\Standards\Configuration;
use Tey\Standards\Conformance;
use Tey\Standards\Enforcement\Gate;

beforeEach(function () {
    $this->vmBase = dirname(__DIR__).'/fixtures/ViewModelRun'.bin2hex(random_bytes(6));
    mkdir($this->vmBase.'/app/ViewModels', 0777, true);
    mkdir($this->vmBase.'/resources/js/pages/Orders/Index', 0777, true);
    $this->configuration = fn (array $options = []): Configuration => new Configuration(...array_replace(get_object_vars(fixtureConfiguration('Good')), ['base' => $this->vmBase, 'viewModelRoots' => ['app/ViewModels'], 'viewModelSuffix' => 'ViewModel', 'frontendRoots' => ['resources/js']], $options));
    $this->viewModel = function (string $body, array $options = []): array {
        file_put_contents($this->vmBase.'/app/ViewModels/OrdersViewModel.php', "<?php\nnamespace App\\ViewModels;\nfinal class OrdersViewModel {\n".$body."\n}\n");

        return (new Conformance(($this->configuration)($options)))->violations('vm.shaped-arrays');
    };
    $this->page = function (string $script): array {
        file_put_contents($this->vmBase.'/resources/js/pages/Orders/Index/Page.vue', "<script setup lang=\"ts\">\n".$script."\n</script>\n<template><div /></template>\n");

        return (new Conformance(($this->configuration)()))->violations('vm.typed-pages');
    };
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->vmBase);
});

test('a ViewModel array prop without a @return shape is a finding; a shape, a generic or a non-array is not', function () {
    $violations = ($this->viewModel)(<<<'PHP'
        public function rows(): array { return []; }
        /** @return list<array{id: int}> */
        public function shaped(): array { return []; }
        /** @return array<string, int> */
        public function counts(): ?array { return []; }
        /** Rows. */
        public function described(): ?array { return null; }
        public function title(): string { return ''; }
        private function helper(): array { return []; }
        public static function make(): array { return []; }
        public function __construct() {}
        PHP);

    expect(array_column($violations, 'check'))->toBe(['array prop without a @return shape: rows()', 'array prop without a @return shape: described()'])
        ->and($violations[0]['target'])->toBe('app/ViewModels/OrdersViewModel.php');
});

test('a page names its generated ViewModel type; inline, untyped or other types are findings, and no props is fine', function () {
    expect(($this->page)("import type { OrdersViewModel } from '@/generated/types';\ndefineProps<OrdersViewModel>();"))->toBe([]);
    expect(($this->page)("withDefaults(defineProps<App.ViewModels.OrdersViewModel>(), {});"))->toBe([]);
    expect(($this->page)("import { ref } from 'vue';\nconst open = ref(false);"))->toBe([]);
    expect(array_column(($this->page)('defineProps<{ title: string }>();'), 'check'))->toBe(['page props restate a type inline instead of naming the generated ViewModel']);
    expect(array_column(($this->page)("defineProps(['title']);"), 'check'))->toBe(['page props are untyped: defineProps() without its ViewModel type']);
    expect(array_column(($this->page)('defineProps<OrderProps>();'), 'check'))->toBe(['page props name OrderProps, not a generated ViewModel type']);
});

test('both rules are advisory in standards:check until an app opts in, then they are drift', function () {
    ($this->viewModel)('public function rows(): array { return []; }');
    ($this->page)('defineProps<{ title: string }>();');
    $paths = ['app/ViewModels/OrdersViewModel.php', 'resources/js/pages/Orders/Index/Page.vue'];

    $advisory = (new Gate(($this->configuration)(), new DateTimeImmutable('2026-11-05')))->run($paths);
    $rules = array_column($advisory['advisories'], 'rule');
    expect($rules)->toContain('vm.shaped-arrays')->toContain('vm.typed-pages')
        ->and(array_column($advisory['violations'], 'rule'))->not->toContain('vm.shaped-arrays')->not->toContain('vm.typed-pages');

    $enforced = (new Gate(($this->configuration)(['optionalRules' => ['vm.shaped-arrays', 'vm.typed-pages']]), new DateTimeImmutable('2026-11-05')))->run($paths);
    expect(array_column($enforced['violations'], 'rule'))->toContain('vm.shaped-arrays')->toContain('vm.typed-pages');
});

test('the id-to-check map names only rules standards has', function () {
    $map = json_decode((string) file_get_contents(dirname(__DIR__).'/resources/patterns/check-ids.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($map['schema'])->toBe(1);
    foreach ($map['map'] as $id => $rules) {
        expect($id)->toMatch('/^[A-Z]+-\d+[a-z]?$/');
        foreach ($rules as $rule) {
            expect(Conformance::TESTS)->toHaveKey($rule);
        }
    }
    expect(array_intersect(Gate::ADVISORY, ['vm.typed-pages', 'vm.shaped-arrays', 'vm.nested-reach']))->toHaveCount(3);
});

test('a page that reads into a structured prop is a finding; scalars, v-if, v-for and passing it whole are not', function () {
    mkdir($this->vmBase.'/resources/js/generated', 0777, true);
    file_put_contents($this->vmBase.'/resources/js/generated/types.ts', <<<'TS'
        export type OrdersViewModel = {
            title: string;
            status: 'open' | 'closed';
            count: number | null;
            limits: { accept: string; maxBytes: number };
            summary: OrderSummary | null;
            rows: OrderRow[];
        };
        TS);
    $check = function (string $template, string $script = ''): array {
        file_put_contents($this->vmBase.'/resources/js/pages/Orders/Index/Page.vue', "<script setup lang=\"ts\">\nimport type { OrdersViewModel } from '@/generated/types';\nconst props = defineProps<OrdersViewModel>();\n".$script."\n</script>\n<template>".$template."</template>\n");

        return array_column((new Conformance(($this->configuration)()))->violations('vm.nested-reach'), 'check');
    };

    expect($check('<h1>{{ title.length }} {{ status }} {{ count }}</h1><Summary v-if="summary" v-bind="summary" /><Row v-for="row in rows" :key="row.id" v-bind="row" /><Limits :limits="limits" />'))->toBe([]);
    expect($check('<p>{{ limits.accept }} {{ limits?.maxBytes }}</p><Total :value="summary.total" />'))->toBe([
        'page reads into its prop limits (accept, maxBytes): hand it whole to a child with v-bind',
        'page reads into its prop summary (total): hand it whole to a child with v-bind',
    ]);
    expect($check('<div />', 'const first = computed(() => props.rows.at(0));'))->toBe(['page reads into its prop rows (at): hand it whole to a child with v-bind']);
    expect($check('<p>{{ order.limits.accept }}</p>'))->toBe([], 'another object\'s field of the same name is not the prop');
});

test('a page whose generated type is not found is not judged', function () {
    file_put_contents($this->vmBase.'/resources/js/pages/Orders/Index/Page.vue', "<script setup lang=\"ts\">\ndefineProps<MissingViewModel>();\n</script>\n<template><p>{{ limits.accept }}</p></template>\n");

    expect((new Conformance(($this->configuration)()))->violations('vm.nested-reach'))->toBe([]);
});
