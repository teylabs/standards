<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Tey\Standards\Conformance;

/** Rule events.storyfeed-side-effect on a scratch app; tenantApp() and tenantConfiguration() come from TenantAwareTest. */
function storyfeedFindings(array $files): array
{
    $base = tenantApp($files);
    try {
        return array_map(fn (array $v): string => $v['target'].':'.$v['line'].' '.strtok($v['check'], ':'), (new Conformance(tenantConfiguration($base)))->violations('events.storyfeed-side-effect'));
    } finally {
        (new Filesystem)->deleteDirectory(dirname($base));
    }
}

test('events.storyfeed-side-effect flags listeners and registrations on ActivityPublished, aliases included, not dispatches or a @feed-presentation class', function () {
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

test('events.storyfeed-side-effect warns only: a finding never fails the Pest run', function () {
    $base = tenantApp(['L.php' => "final class L\n{\n    public function handle(\\Storyfeed\\Events\\ActivityPublished \$e): void {}\n}\n"]);
    try {
        expect(fn () => (new Conformance(tenantConfiguration($base)))->assert('events.storyfeed-side-effect'))->toThrow(PHPUnit\Framework\SkippedWithMessageException::class, 'warns only (1 findings)');
    } finally {
        (new Filesystem)->deleteDirectory(dirname($base));
    }
});
