<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Tey\Standards\Configuration;
use Tey\Standards\Conformance;
use Tey\Standards\Enforcement\CheckReport;
use Tey\Standards\Enforcement\Gate;
use Tey\Standards\Enforcement\Maker;
use Tey\Standards\Patterns\TreeCatalog;

beforeEach(function () {
    $this->ruleBase = dirname(__DIR__).'/fixtures/OperatorRun'.bin2hex(random_bytes(6));
    mkdir($this->ruleBase.'/src/Console', 0777, true);
    $this->configuration = fn (array $options = []): Configuration => new Configuration(...array_replace(get_object_vars(fixtureConfiguration('Good')), ['base' => $this->ruleBase, 'sourceRoots' => ['src']], $options));
    $this->rule = function (string $source, array $options = []): array {
        file_put_contents($this->ruleBase.'/src/Console/Sweep.php', '<?php '.$source);

        return (new Conformance(($this->configuration)($options)))->violations('operator');
    };
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->ruleBase);
});

const OPERATOR_HEAD = 'namespace App\Console; use Illuminate\Console\Command; use Illuminate\Support\Facades\DB; use Tey\Standards\Operator\Operator; ';

test('a command that writes with no dry run and no production guard gets both findings', function () {
    $violations = ($this->rule)(OPERATOR_HEAD."final class Sweep extends Command { protected \$signature = 'app:sweep'; public function handle(): int { DB::table('jobs')->delete(); return 0; } }");

    expect(array_column($violations, 'check'))->toBe([
        'App\Console\Sweep writes without a dry run: run the work through Operator::run, or add --apply (or --dry-run)',
        'App\Console\Sweep writes without a production guard: run the work through Operator::run, or refuse production unless forced',
    ])->and($violations[0]['target'])->toBe('src/Console/Sweep.php');
});

test('Operator::run, or both hand-rolled halves, satisfy it; each half alone does not', function () {
    expect(($this->rule)(OPERATOR_HEAD."final class Sweep extends Command { protected \$signature = 'app:sweep {--apply} {--force}'; public function handle(): int { return Operator::run(\$this, fn (\$run) => \$run->write(fn () => DB::table('jobs')->delete())); } }"))->toBe([]);
    expect(($this->rule)(OPERATOR_HEAD."final class Sweep extends Command { protected \$signature = 'app:sweep {--dry-run}'; public function handle(): int { if (app()->environment('production')) { return 1; } DB::table('jobs')->delete(); return 0; } }"))->toBe([]);
    expect(($this->rule)(OPERATOR_HEAD."final class Sweep extends Command { protected \$signature = 'app:sweep {--apply}'; public function handle(): int { DB::table('jobs')->delete(); return 0; } }"))->toHaveCount(1);
    expect(($this->rule)(OPERATOR_HEAD."final class Sweep extends Command { protected \$signature = 'app:sweep'; public function handle(): int { if (\$this->laravel->isProduction()) { return 1; } DB::table('jobs')->delete(); return 0; } }"))->toHaveCount(1);
});

test('writing through the read-only Operator::report is its own finding', function () {
    expect(array_column(($this->rule)(OPERATOR_HEAD."final class Sweep extends Command { protected \$signature = 'app:sweep'; public function handle(): int { return Operator::report(\$this, fn () => DB::table('jobs')->delete()); } }"), 'check'))
        ->toBe(['App\Console\Sweep writes but runs through Operator::report, which is read-only: use Operator::run']);
});

test('read-only commands, non-commands and file writes are told apart', function () {
    expect(($this->rule)(OPERATOR_HEAD."final class Sweep extends Command { protected \$signature = 'app:sweep'; public function handle(): int { \$this->info((string) DB::table('jobs')->count()); return 0; } }"))->toBe([]);
    expect(($this->rule)(OPERATOR_HEAD."final class Sweep { public function handle(): void { DB::table('jobs')->delete(); } }"))->toBe([]);
    expect(($this->rule)(OPERATOR_HEAD."final class Sweep extends Command { protected \$signature = 'app:sweep'; public function handle(): int { file_put_contents('x', 'y'); return 0; } }"))->toHaveCount(2);
});

test('the rule is advisory in standards:check until an app opts in, then it is drift', function () {
    file_put_contents($this->ruleBase.'/src/Console/Sweep.php', '<?php '.OPERATOR_HEAD."final class Sweep extends Command { protected \$signature = 'app:sweep'; public function handle(): int { DB::table('jobs')->delete(); return 0; } }");

    $advisory = (new Gate(($this->configuration)(), new DateTimeImmutable('2026-10-27')))->run(['src/Console/Sweep.php']);
    expect(array_column($advisory['advisories'], 'rule'))->toBe(['operator', 'operator'])
        ->and(array_column($advisory['advisories'], 'kind'))->toBe(['advisory', 'advisory'])
        ->and(array_column($advisory['violations'], 'rule'))->not->toContain('operator');

    $enforced = (new Gate(($this->configuration)(['optionalRules' => ['operator']]), new DateTimeImmutable('2026-10-27')))->run(['src/Console/Sweep.php']);
    expect($enforced['advisories'])->toBe([])
        ->and(array_column(array_filter($enforced['violations'], fn (array $v): bool => $v['rule'] === 'operator'), 'kind'))->toBe(['violation', 'violation']);
});

test('standards:make command stamps all three flavors, with a test each, and they pass standards:check with no advisory', function () {
    $base = fixtureApp();

    try {
        $maker = new Maker($base, TreeCatalog::shipped());
        $files = [...$maker->make('command', 'BackfillTotals', 'backfill'), ...$maker->make('command', 'PruneVisits', 'maintenance'), ...$maker->make('command', 'CountVisits', 'report')];

        expect($files)->toBe(['app/Console/Commands/BackfillTotals.php', 'tests/Feature/Console/BackfillTotalsTest.php', 'app/Console/Commands/PruneVisits.php', 'tests/Feature/Console/PruneVisitsTest.php', 'app/Console/Commands/CountVisits.php', 'tests/Feature/Console/CountVisitsTest.php']);
        foreach ($files as $file) {
            expect(file_get_contents($base.'/'.$file))->toContain('standards-pattern: background.command');
        }
        expect(file_get_contents($base.'/app/Console/Commands/BackfillTotals.php'))->toContain("app:backfill-totals {--apply")->toContain('Operator::run(')
            ->and(file_get_contents($base.'/app/Console/Commands/CountVisits.php'))->toContain('Operator::report(')->not->toContain('{--apply')
            ->and(file_get_contents($base.'/tests/Feature/Console/BackfillTotalsTest.php'))->toContain('writes nothing in a dry run')->toContain('applies once, and a re-run is already done')->toContain('refuses to apply in production');

        $report = CheckReport::build($base, 'tests/Arch/configuration.php', null, 'full', new DateTimeImmutable('2026-10-27'), TreeCatalog::shipped());
        expect($report['violations'])->toBe([])->and($report['advisories'])->toBe([])->and($report['exit_code'])->toBe(0);
        expect(fn () => $maker->make('command', 'BackfillTotals', 'backfill'))->toThrow(InvalidArgumentException::class, 'never overwrites')
            ->and(fn () => $maker->make('command', 'Other', 'plain'))->toThrow(InvalidArgumentException::class, 'Unknown unit or flavor');
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }
});
