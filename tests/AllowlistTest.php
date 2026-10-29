<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Tester\CommandTester;
use Tey\Standards\Enforcement\AllowlistCommand;
use Tey\Standards\Enforcement\AllowlistStamper;
use Tey\Standards\Ratchet;

final class AllowlistTemp
{
    /** @var list<string> */
    public static array $dirs = [];
}

/** An app with tests/Arch/configuration.php requiring an allowlist in the usual hand-written shape: two undated entries and one dated. */
function allowlistApp(): string
{
    $base = sys_get_temp_dir().'/standards-allowlist-'.bin2hex(random_bytes(6));
    mkdir($base.'/tests/Arch', 0777, true);
    AllowlistTemp::$dirs[] = $base;
    file_put_contents($base.'/tests/Arch/configuration.php', "<?php\n\nreturn new Tey\\Standards\\Configuration(\n    base: dirname(__DIR__, 2),\n    allowlist: require __DIR__.'/debt.php',\n);\n");
    file_put_contents($base.'/tests/Arch/debt.php', <<<'PHP'
        <?php

        // Known debt, paid down by the dates below.
        return [
            [
                'rule' => '19',
                'target' => 'App\\Models\\Concerns\\HasPhoto',
                'check' => 'media model must implement HasMedia',
                'reason' => 'A trait cannot implement an interface.',
                'date' => '2026-10-13',
            ],
            [
                'rule' => '21', 'target' => 'app/Http/Controllers/AccountController.php', 'check' => 'method | AccountController::updatePhoto',
                'reason' => 'Kept for a later migration.',
                'date' => '2026-10-13', // until the noun controller lands
            ],
            [
                'rule' => '21',
                'target' => 'app/Http/Controllers/AccountController.php',
                'check' => 'method | AccountController::destroyPhoto',
                'reason' => 'Kept for a later migration.',
                'date' => '2026-10-13',
                'expires' => '2027-01-31',
            ],
        ];

        PHP);

    return $base;
}

afterEach(function () {
    foreach (AllowlistTemp::$dirs as $base) {
        (new Filesystem)->deleteDirectory($base);
    }
    AllowlistTemp::$dirs = [];
});

test('the Pest suite reports an allowlist entry with no expiry, as standards:check does (missing-expiry)', function () {
    $entry = ['rule' => '21', 'target' => 'a.php', 'check' => 'method | A::b', 'reason' => 'why', 'date' => '2026-10-13'];
    $finding = [['target' => 'a.php', 'check' => 'method | A::b']];

    expect((new Ratchet([$entry]))->failures('21', $finding))->toBe(['exception has no expiry, give it an `expires` Y-m-d date (php artisan standards:allowlist --stamp-expiry=YYYY-MM-DD dates them all): a.php | method | A::b'])
        ->and((new Ratchet([[...$entry, 'expires' => '2027-01-31']]))->failures('21', $finding))->toBe([]);
});

test('--stamp-expiry dates every undated entry after its date line, keeps a dated one, and changes nothing else', function () {
    $base = allowlistApp();
    $before = (string) file_get_contents($base.'/tests/Arch/debt.php');
    $stamper = new AllowlistStamper($base);
    $plan = $stamper->plan('2027-03-31', today: new DateTimeImmutable('2026-10-28'));

    expect($plan['file'])->toBe('tests/Arch/debt.php')
        ->and(count($plan['stamped']))->toBe(2)
        ->and($plan['kept'])->toBe(1)
        ->and((string) file_get_contents($base.'/tests/Arch/debt.php'))->toBe($before, 'planning writes nothing');

    $stamper->apply($plan);
    $after = (string) file_get_contents($base.'/tests/Arch/debt.php');
    $entries = require $base.'/tests/Arch/debt.php';

    expect(array_column($entries, 'expires'))->toBe(['2027-03-31', '2027-03-31', '2027-01-31'])
        ->and(preg_replace("/^        'expires' => '2027-03-31',\n/m", '', $after))->toBe($before, 'only the expiry lines are added, each indented like its entry')
        ->and((new Ratchet($entries))->failures('21', [['target' => 'app/Http/Controllers/AccountController.php', 'check' => 'method | AccountController::updatePhoto'], ['target' => 'app/Http/Controllers/AccountController.php', 'check' => 'method | AccountController::destroyPhoto']]))->toBe([]);

    $again = $stamper->plan('2028-01-01', today: new DateTimeImmutable('2026-10-28'));

    expect($again['stamped'])->toBe([])->and($again['kept'])->toBe(3)->and($again['contents'])->toBe($after, 'an existing expiry is never changed');
});

test('--stamp-expiry refuses a date that is not real or already past', function (string $date, string $message) {
    expect(fn () => (new AllowlistStamper(allowlistApp()))->plan($date, today: new DateTimeImmutable('2026-10-28')))->toThrow(InvalidArgumentException::class, $message);
})->with([
    ['2027-02-30', 'needs a real Y-m-d date'],
    ['2026-10-01', 'is in the past'],
]);

test('standards:allowlist --stamp-expiry --dry-run --json plans without writing; without --dry-run it writes', function () {
    $base = allowlistApp();
    $before = (string) file_get_contents($base.'/tests/Arch/debt.php');
    $run = function (array $options) use ($base): CommandTester {
        $command = new AllowlistCommand;
        $command->setLaravel(new Illuminate\Foundation\Application($base));
        $tester = new CommandTester($command);
        $tester->execute($options);

        return $tester;
    };

    $dry = $run(['--stamp-expiry' => '2099-12-31', '--dry-run' => true, '--json' => true]);
    $json = json_decode($dry->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

    expect($dry->getStatusCode())->toBe(0)
        ->and($json)->toMatchArray(['dry_run' => true, 'file' => 'tests/Arch/debt.php', 'expires' => '2099-12-31', 'stamped' => 2, 'kept' => 1])
        ->and((string) file_get_contents($base.'/tests/Arch/debt.php'))->toBe($before);

    $write = $run(['--stamp-expiry' => '2099-12-31']);

    expect($write->getStatusCode())->toBe(0)
        ->and($write->getDisplay())->toContain('Stamped 2 entries in tests/Arch/debt.php with expires 2099-12-31; 1 already dated, unchanged.')
        ->and(array_column(require $base.'/tests/Arch/debt.php', 'expires'))->toBe(['2099-12-31', '2099-12-31', '2027-01-31']);

    expect($run(['--stamp-expiry' => 'soon'])->getStatusCode())->toBe(2);
});

/** An allowlist file at tests/Arch/conformance-allowlist.php (the default) with the given body. */
function allowlistFile(string $body): string
{
    $base = sys_get_temp_dir().'/standards-allowlist-'.bin2hex(random_bytes(6));
    mkdir($base.'/tests/Arch', 0777, true);
    AllowlistTemp::$dirs[] = $base;
    file_put_contents($base.'/tests/Arch/conformance-allowlist.php', $body);

    return $base;
}

test('--stamp-expiry dates one-line entries straight after their date pair, alone and mixed with multi-line ones', function () {
    $base = allowlistFile(<<<'PHP'
        <?php

        return [
            ['rule' => '21', 'target' => 'a.php', 'check' => 'method | A::b', 'reason' => 'Later.', 'date' => '2026-10-13'],
            ['rule' => '21', 'target' => 'b.php', 'check' => 'method | B::c', 'date' => '2026-10-13', 'reason' => 'Date mid-line.'],
            [
                'rule' => '20',
                'target' => 'c.vue',
                'check' => 'page path must use PascalCase',
                'reason' => 'Starter scaffold.',
                'date' => '2026-10-13',
            ],
            ['rule' => '21', 'target' => 'd.php', 'check' => 'method | D::e', 'reason' => 'Dated.', 'date' => '2026-10-13', 'expires' => '2027-01-31'],
        ];

        PHP);
    $stamper = new AllowlistStamper($base);
    $plan = $stamper->plan('2027-03-31', today: new DateTimeImmutable('2026-10-28'));
    $stamper->apply($plan);
    $after = (string) file_get_contents($base.'/tests/Arch/conformance-allowlist.php');

    expect(count($plan['stamped']))->toBe(3)
        ->and($plan['kept'])->toBe(1)
        ->and($plan['skipped'])->toBe([])
        ->and(array_column(require $base.'/tests/Arch/conformance-allowlist.php', 'expires'))->toBe(['2027-03-31', '2027-03-31', '2027-03-31', '2027-01-31'])
        ->and($after)->toContain("'reason' => 'Later.', 'date' => '2026-10-13', 'expires' => '2027-03-31'],")
        ->toContain("'date' => '2026-10-13', 'expires' => '2027-03-31', 'reason' => 'Date mid-line.'],")
        ->toContain("        'date' => '2026-10-13',\n        'expires' => '2027-03-31',\n");
});

test('an entry with no literal date is skipped and reported; the run stamps the rest instead of aborting', function () {
    $base = allowlistFile(<<<'PHP'
        <?php

        $today = '2026-10-13';

        return [
            ['rule' => '21', 'target' => 'a.php', 'check' => 'method | A::b', 'reason' => 'Later.', 'date' => '2026-10-13'],
            ['rule' => '21', 'target' => 'b.php', 'check' => 'method | B::c', 'reason' => 'Computed date.', 'date' => $today],
        ];

        PHP);
    $command = new AllowlistCommand;
    $command->setLaravel(new Illuminate\Foundation\Application($base));
    $tester = new CommandTester($command);
    $tester->execute(['--stamp-expiry' => '2099-12-31']);
    $entries = require $base.'/tests/Arch/conformance-allowlist.php';

    expect($tester->getStatusCode())->toBe(0)
        ->and($tester->getDisplay())->toContain('Stamped 1 entry')->toContain("skipped [21] b.php: no literal 'date' => '…' to stamp after; date it by hand")
        ->and($entries[0]['expires'] ?? null)->toBe('2099-12-31')
        ->and(array_key_exists('expires', $entries[1]))->toBeFalse();

    $json = new CommandTester($command);
    $json->execute(['--stamp-expiry' => '2099-12-31', '--dry-run' => true, '--json' => true]);

    expect(json_decode($json->getDisplay(), true, flags: JSON_THROW_ON_ERROR)['skipped'])->toBe([['rule' => '21', 'target' => 'b.php', 'check' => 'method | B::c', 'reason' => "no literal 'date' => '…' to stamp after; date it by hand"]]);
});
