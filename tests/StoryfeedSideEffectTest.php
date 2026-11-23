<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Tey\Standards\Conformance;

/** Rule events.feed-side-effect on a scratch app; tenantApp() and tenantConfiguration() come from TenantAwareTest. */
function storyfeedFindings(array $files): array
{
    $base = tenantApp($files);
    try {
        return array_map(fn (array $v): string => $v['target'].':'.$v['line'].' '.strtok($v['check'], ':'), (new Conformance(tenantConfiguration($base)))->violations('events.feed-side-effect'));
    } finally {
        (new Filesystem)->deleteDirectory(dirname($base));
    }
}

test('events.feed-side-effect flags listeners and registrations on ActivityPublished, aliases included, not dispatches or a @feed-presentation class', function () {
    expect(storyfeedFindings([
        'ActivatePaidJoins.php' => "use Storyfeed\\Events\\ActivityPublished;\n\nfinal class ActivatePaidJoins\n{\n    public function handle(ActivityPublished \$event): void {}\n}\n",
        'Provider.php' => "use Storyfeed\\Events\\ActivityPublished as Published;\n\nfinal class Provider\n{\n    public function boot(): void\n    {\n        Event::listen(Published::class, fn () => null);\n        event(new Published);\n        \$this->map = [Published::class => [X::class]];\n        \$name = Published::class;\n    }\n}\n",
        'FeedCard.php' => "use Storyfeed\\Events\\ActivityPublished;\n\n/**\n * @feed-presentation groups the feed card by actor\n */\nfinal class FeedCard\n{\n    public function handle(ActivityPublished \$event): void {}\n}\n",
        'Untagged.php' => "use Storyfeed\\Events\\ActivityPublished;\n\n/** @feed-presentation */\nfinal class Untagged\n{\n    public function handle(ActivityPublished \$event): void {}\n}\n",
    ]))->toBe([
        "src/ActivatePaidJoins.php:9 a listener takes Storyfeed's ActivityPublished",
        "src/Provider.php:11 registers a listener on Storyfeed's ActivityPublished",
        "src/Provider.php:13 registers a listener on Storyfeed's ActivityPublished",
        "src/Untagged.php:10 a listener takes Storyfeed's ActivityPublished",
    ]);
});

test('events.feed-side-effect covers ActivityDeleted, and an inline publish in an Action but not elsewhere or on another chain', function () {
    expect(storyfeedFindings([
        'Revoke.php' => "final class Revoke\n{\n    public function handle(\\Storyfeed\\Events\\ActivityDeleted \$e): void {}\n}\n",
        'Actions/PlaceOrder.php' => "use Storyfeed\\Facades\\Storyfeed as Feed;\n\nfinal class PlaceOrder\n{\n    public function handle(): void\n    {\n        Feed::activity()->by(\$u)->action('place', \$o)->publish();\n        Feed::publish(\$story);\n        \$this->post->publish();\n    }\n}\n",
        'Console/Backfill.php' => "use Storyfeed\\Facades\\Storyfeed;\n\nfinal class Backfill\n{\n    public function handle(): void\n    {\n        Storyfeed::activity()->publish();\n    }\n}\n",
    ]))->toBe([
        'src/Actions/PlaceOrder.php:11 publishes to Storyfeed inline in an Action',
        'src/Actions/PlaceOrder.php:12 publishes to Storyfeed inline in an Action',
        "src/Revoke.php:7 a listener takes Storyfeed's ActivityDeleted",
    ]);
});

test('events.feed-side-effect flags each Storyfeed event in each listening form, and not the Snapshots payload types', function (string $event) {
    $fqcn = 'Storyfeed\\Events\\'.$event;
    $files = [
        'Typed.php' => "use {$fqcn};\n\nfinal class Typed\n{\n    public function handle({$event} \$e): void {}\n}\n",
        'Listen.php' => "use {$fqcn};\n\nfinal class Listen\n{\n    public function boot(): void\n    {\n        Event::listen({$event}::class, X::class);\n    }\n}\n",
        'Map.php' => "use {$fqcn};\n\nfinal class Map\n{\n    protected \$listen = [{$event}::class => [X::class]];\n}\n",
        'Sub.php' => "use {$fqcn};\n\nfinal class Sub\n{\n    public function subscribe(): array\n    {\n        return [{$event}::class => 'onIt'];\n    }\n}\n",
        'Snap.php' => "use Storyfeed\\Events\\Snapshots\\ActivitySnapshot;\nuse Storyfeed\\Events\\Snapshots\\BatchSnapshot;\n\nfinal class Snap\n{\n    public function handle(ActivitySnapshot \$a, BatchSnapshot \$b): void\n    {\n        Event::listen(ActivitySnapshot::class, X::class);\n    }\n}\n",
    ];

    expect(storyfeedFindings($files))->toBe([
        "src/Listen.php:11 registers a listener on Storyfeed's {$event}",
        "src/Map.php:9 registers a listener on Storyfeed's {$event}",
        "src/Sub.php:11 registers a listener on Storyfeed's {$event}",
        "src/Typed.php:9 a listener takes Storyfeed's {$event}",
    ]);
})->with(['ActivityPublished', 'ActivityDeleted', 'BatchClosed']);

test('events.feed-side-effect flags Storyfeed::publish() in an Action, and exempts console commands, seeders and migrations', function () {
    $publish = "    public function handle(): void\n    {\n        Storyfeed::publish(\$story);\n    }\n";
    expect(storyfeedFindings([
        'Actions/ConfirmBooking.php' => "use Storyfeed\\Facades\\Storyfeed;\n\nfinal class ConfirmBooking\n{\n{$publish}}\n",
        'Console/Actions/ImportBookings.php' => "use Storyfeed\\Facades\\Storyfeed;\n\nfinal class ImportBookings\n{\n{$publish}}\n",
        'Actions/BackfillFeed.php' => "use Illuminate\\Console\\Command;\nuse Storyfeed\\Facades\\Storyfeed;\n\nfinal class BackfillFeed extends Command\n{\n{$publish}}\n",
        'database/seeders/Actions/SeedFeed.php' => "use Illuminate\\Database\\Seeder;\nuse Storyfeed\\Facades\\Storyfeed;\n\nfinal class SeedFeed extends Seeder\n{\n{$publish}}\n",
        'Actions/AddFeedRows.php' => "use Illuminate\\Database\\Migrations\\Migration;\nuse Storyfeed\\Facades\\Storyfeed;\n\nreturn new class extends Migration\n{\n{$publish}};\n",
    ]))->toBe(['src/Actions/ConfirmBooking.php:11 publishes to Storyfeed inline in an Action']);
});

test('events.feed-side-effect warns only: a finding never fails the Pest run', function () {
    $base = tenantApp(['L.php' => "final class L\n{\n    public function handle(\\Storyfeed\\Events\\ActivityPublished \$e): void {}\n}\n"]);
    try {
        expect(fn () => (new Conformance(tenantConfiguration($base)))->assert('events.feed-side-effect'))->toThrow(PHPUnit\Framework\SkippedWithMessageException::class, 'warns only (1 findings)');
    } finally {
        (new Filesystem)->deleteDirectory(dirname($base));
    }
});

test('events.feed-side-effect counts Storyfeed::record() in an Action as an inline publish, as storyfeed:doctor does', function () {
    expect(storyfeedFindings(['Actions/Note.php' => "use Storyfeed\\Facades\\Storyfeed;\n\nfinal class Note\n{\n    public function handle(): void\n    {\n        Storyfeed::record('x');\n    }\n}\n"]))
        ->toBe(['src/Actions/Note.php:11 publishes to Storyfeed inline in an Action']);
});
