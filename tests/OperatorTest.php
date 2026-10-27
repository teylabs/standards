<?php

declare(strict_types=1);

use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository;
use Illuminate\Console\Application;
use Illuminate\Console\Command;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Symfony\Component\Console\Tester\CommandTester;
use Tey\Standards\Operator\Operator;
use Tey\Standards\Operator\OperatorRun;

/** A backfill over an in-memory list: null values get filled, set ones are already done, `boom` throws with a secret in its message. */
final class OperatorFillCommand extends Command
{
    /** @var array<string, string|null> */
    public static array $rows = [];

    protected $signature = 'ops:fill {--apply} {--force}';

    public function handle(): int
    {
        return Operator::run($this, function (OperatorRun $run): void {
            $run->each(array_keys(self::$rows), function (string $key, OperatorRun $run): void {
                if ($key === 'boom') {
                    throw new RuntimeException('secret-token-123 for someone@example.test');
                }
                if (self::$rows[$key] !== null) {
                    $run->skipped('already done');

                    return;
                }
                $run->write(function () use ($key): void {
                    self::$rows[$key] = 'filled';
                });
                $run->changed();
            });
        });
    }
}

final class OperatorCountCommand extends Command
{
    protected $signature = 'ops:count';

    public function handle(): int
    {
        return Operator::report($this, function (OperatorRun $run): void {
            $run->each(OperatorFillCommand::$rows, fn (?string $value, OperatorRun $run) => $value === null ? $run->skipped('not set') : $run->changed());
        });
    }
}

final class OperatorUnguardedCommand extends Command
{
    protected $signature = 'ops:unguarded';

    public function handle(): int
    {
        return Operator::run($this, fn (OperatorRun $run) => null);
    }
}

beforeEach(function () {
    $container = new class extends Container
    {
        public function runningUnitTests(): bool
        {
            return true;
        }

        public function runningInConsole(): bool
        {
            return true;
        }
    };
    $container->instance('config', $this->config = new Repository(['cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]], 'standards' => ['operator' => ['allow' => []]]]));
    $container->instance('cache', new CacheManager($container));
    $container->instance('env', 'local');
    $this->container = $container;
    $this->artisan = new Application($container, new Dispatcher($container), 'test');
    $this->artisan->resolveCommands([OperatorFillCommand::class, OperatorCountCommand::class, OperatorUnguardedCommand::class]);
    $this->run = function (string $name, array $input = [], array $answers = [], bool $interactive = true): CommandTester {
        $tester = new CommandTester($this->artisan->find($name));
        $tester->setInputs($answers);
        $tester->execute($input, ['interactive' => $interactive]);

        return $tester;
    };
    OperatorFillCommand::$rows = ['a' => null, 'b' => null, 'c' => 'set'];
    putenv('STANDARDS_OPERATOR_ALLOW');
});

test('a run without --apply is a dry run: it counts and writes nothing', function () {
    $tester = ($this->run)('ops:fill');

    expect($tester->getStatusCode())->toBe(0)
        ->and($tester->getDisplay())->toContain('dry run')->toContain('Would change')->toContain('Dry run: nothing was written. Run again with --apply to write.')->toContain('skipped 1: already done')
        ->and(OperatorFillCommand::$rows)->toBe(['a' => null, 'b' => null, 'c' => 'set']);
});

test('--apply writes, and a re-run is already done with its counts', function () {
    expect(($this->run)('ops:fill', ['--apply' => true])->getStatusCode())->toBe(0)
        ->and(OperatorFillCommand::$rows)->toBe(['a' => 'filled', 'b' => 'filled', 'c' => 'set']);

    $again = ($this->run)('ops:fill', ['--apply' => true]);
    expect($again->getStatusCode())->toBe(0)
        ->and($again->getDisplay())->toContain('Already done: nothing to change.')->toContain('skipped 3: already done')->toMatch('/applied\s*\|\s*3\s*\|\s*0\s*\|\s*3\s*\|\s*0/');
});

test('production refuses --apply unless forced and the name is typed back, and never in a non-interactive run', function () {
    $this->container->instance('env', 'production');

    $refused = ($this->run)('ops:fill', ['--apply' => true]);
    expect($refused->getStatusCode())->toBe(Operator::REFUSED)->and($refused->getDisplay())->toContain('Refused: ops:fill writes, and this is production');
    expect(($this->run)('ops:fill', ['--apply' => true, '--force' => true], ['nope'])->getStatusCode())->toBe(Operator::REFUSED);
    expect(($this->run)('ops:fill', ['--apply' => true, '--force' => true], [], false)->getStatusCode())->toBe(Operator::REFUSED);
    expect(OperatorFillCommand::$rows['a'])->toBeNull();

    // A dry run is read-only, so production allows it.
    expect(($this->run)('ops:fill')->getStatusCode())->toBe(0);

    expect(($this->run)('ops:fill', ['--apply' => true, '--force' => true], ['ops:fill'])->getStatusCode())->toBe(0)
        ->and(OperatorFillCommand::$rows['a'])->toBe('filled');
});

test('an allow-listed command applies in production without the prompt, by config or by env', function () {
    $this->container->instance('env', 'production');
    $this->config->set('standards.operator.allow', ['ops:fill']);
    expect(($this->run)('ops:fill', ['--apply' => true])->getStatusCode())->toBe(0);

    OperatorFillCommand::$rows = ['a' => null];
    $this->config->set('standards.operator.allow', []);
    putenv('STANDARDS_OPERATOR_ALLOW=ops:other, ops:fill');
    expect(($this->run)('ops:fill', ['--apply' => true])->getStatusCode())->toBe(0)->and(OperatorFillCommand::$rows['a'])->toBe('filled');
    putenv('STANDARDS_OPERATOR_ALLOW');
});

test('one run at a time: a held lock refuses, and no lock store fails closed', function () {
    $lock = $this->container->make('cache')->store()->getStore()->lock('standards-operator:ops:fill', 60);
    expect($lock->get())->toBeTrue();
    $tester = ($this->run)('ops:fill', ['--apply' => true]);
    expect($tester->getStatusCode())->toBe(Operator::REFUSED)->and($tester->getDisplay())->toContain('is already running')->and(OperatorFillCommand::$rows['a'])->toBeNull();
    $lock->release();

    $this->container->forgetInstance('cache');
    $this->container->offsetUnset('cache');
    expect(($this->run)('ops:fill', ['--apply' => true])->getStatusCode())->toBe(Operator::REFUSED);
});

test('an item that throws is counted as failed by its class, the rest carry on, the exit is 1 and no message or data is printed', function () {
    OperatorFillCommand::$rows = ['a' => null, 'boom' => null, 'b' => null];
    $tester = ($this->run)('ops:fill', ['--apply' => true]);

    expect($tester->getStatusCode())->toBe(1)
        ->and($tester->getDisplay())->toContain('failed 1: exception runtime exception')->not->toContain('secret-token-123')->not->toContain('someone@example.test')
        ->and(OperatorFillCommand::$rows)->toBe(['a' => 'filled', 'boom' => null, 'b' => 'filled']);
});

test('a reason is a short label, never record data', function () {
    $run = new OperatorRun(true);

    expect(fn () => $run->skipped('someone@example.test'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $run->failed(str_repeat('x', 60)))->toThrow(InvalidArgumentException::class);
    $run->skipped('Already Done');
    expect($run->counts()['skipped_by'])->toBe(['already done' => 1]);
});

test('Operator::run needs --apply and --force in the signature', function () {
    expect(fn () => ($this->run)('ops:unguarded'))->toThrow(LogicException::class, 'its signature has no --apply option');
});

test('Operator::report is read-only: no --apply, it runs in production, and its column is Matched', function () {
    $this->container->instance('env', 'production');
    $tester = ($this->run)('ops:count');

    expect($tester->getStatusCode())->toBe(0)
        ->and($tester->getDisplay())->toContain('report')->toContain('Matched')->toContain('skipped 2: not set')->not->toContain('Already done')
        ->and(OperatorFillCommand::$rows)->toBe(['a' => null, 'b' => null, 'c' => 'set']);
});
