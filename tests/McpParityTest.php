<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Tey\Standards\Checks\McpParity;
use Tey\Standards\Ratchet;

beforeEach(function () {
    $this->base = dirname(__DIR__).'/fixtures/McpRun'.bin2hex(random_bytes(6));
    $this->files = new Filesystem;
    $this->files->copyDirectory(dirname(__DIR__).'/fixtures/Good', $this->base);
    $this->manifest = $this->base.'/docs/mcp-capabilities.json';
});

afterEach(function () {
    // Windows cannot recurse through a directory symlink, so unlink links first or the run folder is left behind.
    foreach (['src/Linked', 'src/UI/Linked.php', 'docs/mcp-capabilities.json'] as $link) {
        if (is_link($this->base.'/'.$link)) {
            is_dir($this->base.'/'.$link) && PHP_OS_FAMILY === 'Windows' ? @rmdir($this->base.'/'.$link) : @unlink($this->base.'/'.$link);
        }
    }

    $this->files->deleteDirectory($this->base);
});

test('MCP declaration findings support exact ratchets and stale entry detection', function () {
    $bad = (new McpParity(dirname(__DIR__).'/fixtures/Bad'))->violations();
    expect(array_column($bad, 'check'))->toBe([
        'capability 1 has a missing or incomplete MCP path',
        'UI inventory entry 1 has missing or unmapped semantic variants',
        // The Bad fixture's mutating routes are declared nowhere in its manifest.
        'mutating route POST /invoices/pay -> FixtureBad\\Domain\\Billing\\Http\\Controllers\\InvoiceController@pay has no MCP capability or uiOnly declaration',
        'mutating route POST /invoices (invoices.store) -> FixtureBad\\Domain\\Billing\\Http\\Controllers\\InvoiceController@store has no MCP capability or uiOnly declaration',
        'mutating route PUT|PATCH /invoices/{invoice} (invoices.update) -> FixtureBad\\Domain\\Billing\\Http\\Controllers\\InvoiceController@update has no MCP capability or uiOnly declaration',
        'mutating route DELETE /invoices/{invoice} (invoices.destroy) -> FixtureBad\\Domain\\Billing\\Http\\Controllers\\InvoiceController@destroy has no MCP capability or uiOnly declaration',
        'mutating route POST /invoices/reorder -> FixtureBad\\Domain\\Billing\\Http\\Controllers\\InvoiceController@reorder has no MCP capability or uiOnly declaration',
    ]);
    $ratchet = new Ratchet(array_map(fn (array $finding): array => ['rule' => '55', ...$finding, 'reason' => 'Fixture MCP debt', 'date' => '2026-10-24', 'expires' => '2099-12-31'], $bad));
    expect($ratchet->failures('55', $bad))->toBe([])
        ->and($ratchet->failures('55', (new McpParity($this->base))->violations()))->toHaveCount(7);
});

test('MCP declarations reject malformed JSON and preserve JSON value types', function (string $json, string $check) {
    file_put_contents($this->manifest, $json);
    expect(array_column((new McpParity($this->base))->violations(), 'check'))->toBe([$check]);
})->with([
    ['{', 'capability inventory is not readable JSON'],
    ['null', 'capability inventory needs version 1'],
    ['{"version":true}', 'capability inventory needs version 1'],
    ['{"version":1.0}', 'capability inventory needs version 1'],
    ['{"version":1,"inventory":{},"capabilities":{}}', 'non-empty UI inventory and capabilities are required'],
]);

test('MCP references reject absent, hidden, traversal and symlink paths without exposing contents', function () {
    $data = json_decode(file_get_contents($this->manifest));
    symlink($this->base.'/src/UI/Link.php', $this->base.'/src/UI/Linked.php');
    symlink($this->base.'/src/UI', $this->base.'/src/Linked');
    file_put_contents($this->base.'/.env', 'never print this content');
    foreach (['src/Absent.php', 'src/UI/../UI/Link.php', '/src/UI/Link.php', 'src//UI/Link.php', 'src/UI/./Link.php', 'src/UI/Linked.php', 'src/Linked/Link.php', '.env', 'src\\UI\\Link.php'] as $path) {
        $data->capabilities[0]->domain->path = $path;
        file_put_contents($this->manifest, json_encode($data));
        expect(array_column((new McpParity($this->base))->violations(), 'check'))->toBe(['capability 1 needs an existing domain operation or binding reference']);
    }
    unlink($this->manifest);
    symlink($this->base.'/.env', $this->manifest);
    expect(array_column((new McpParity($this->base))->violations(), 'check'))->toBe(['capability inventory is missing or is a symlink']);
});

test('MCP package references require a locked installed source while tests stay host owned', function () {
    $data = json_decode(file_get_contents($this->manifest));
    $path = 'vendor/acme/agent/src/ReadRecord.php';
    $this->files->ensureDirectoryExists(dirname($this->base.'/'.$path));
    file_put_contents($this->base.'/'.$path, '<?php');
    $data->capabilities[0]->mcp->entrypoint->path = $path;
    file_put_contents($this->manifest, json_encode($data));
    expect((new McpParity($this->base))->violations())->toHaveCount(1);
    file_put_contents($this->base.'/composer.lock', '{"packages-dev":[{"name":"acme/agent"}]}');
    expect((new McpParity($this->base))->violations())->toBe([]);
    $data->capabilities[0]->tests[0]->path = $path;
    file_put_contents($this->manifest, json_encode($data));
    expect(array_column((new McpParity($this->base))->violations(), 'check'))->toContain('capability 1 has an invalid parity test reference');
});

test('MCP declarations enforce adapter contracts and inventory completeness', function (Closure $mutate, string $expected) {
    $data = json_decode(file_get_contents($this->manifest));
    $mutate($data);
    file_put_contents($this->manifest, json_encode($data));
    expect(array_column((new McpParity($this->base))->violations(), 'check'))->toContain($expected);
})->with([
    // Each row exercises a different declaration boundary.
    'write coverage' => [function (object $data) {
        $data->capabilities[0]->kind = 'write';
    }, 'capability 1 lacks contract coverage: audit, retry, stale'],
    'external consent' => [function (object $data) {
        $data->capabilities[0]->kind = 'external';
    }, 'capability 1 needs an explicit consequential-action confirmation contract'],
    'human handoff' => [function (object $data) {
        $data->capabilities[0]->mcp->kind = 'protocol';
    }, 'capability 1 needs a human handoff and authoritative readback contract'],
    'malformed adapter' => [function (object $data) {
        $data->capabilities[0]->mcp->kind = [];
    }, 'capability 1 needs an MCP entrypoint, name, kind and explicit scopes'],
    'normalized types' => [function (object $data) {
        $data->capabilities[0]->variants[0]->ui->options->enabled = false;
        $data->capabilities[0]->variants[0]->mcp->options->enabled = 0;
    }, 'capability 1 variant 1 must match normalized options, defaults and ordered effects'],
    'effect order' => [function (object $data) {
        $data->capabilities[0]->variants[0]->ui->effects = ['read', 'audit'];
        $data->capabilities[0]->variants[0]->mcp->effects = ['audit', 'read'];
    }, 'capability 1 variant 1 must match normalized options, defaults and ordered effects'],
    'mode recovery' => [function (object $data) {
        $data->capabilities[0]->variants[0]->tests[0]->covers = ['success'];
    }, 'capability 1 variant 1 lacks contract coverage: error, recovery'],
    'excluded mode' => [function (object $data) {
        $data->capabilities[0]->variants[0]->mcp->status = 'excluded';
    }, 'capability 1 variant 1 has a missing, partial or excluded MCP mode'],
    'missing UI inventory' => [function (object $data) {
        $data->inventory[0]->path = 'tests/Parity.php';
    }, 'a capability UI reference is absent from the reviewed inventory'],
    'unknown mode' => [function (object $data) {
        $data->inventory[0]->variants->{'record.read'} = ['unknown'];
    }, 'UI inventory entry 1 has missing or unmapped semantic variants'],
    'duplicate capability' => [function (object $data) {
        $data->capabilities[] = clone $data->capabilities[0];
    }, 'capability 2 needs a unique stable id'],
    'duplicate variant' => [function (object $data) {
        $data->capabilities[0]->variants[] = clone $data->capabilities[0]->variants[0];
    }, 'capability 1 variant 2 needs a unique stable id'],
]);

test('MCP semantic object key order has no effect but list order and types do', function () {
    $data = json_decode(file_get_contents($this->manifest));
    $data->capabilities[0]->variants[0]->ui->options = (object) ['enabled' => true, 'fields' => ['title', 'date']];
    $data->capabilities[0]->variants[0]->mcp->options = (object) ['fields' => ['title', 'date'], 'enabled' => true];
    file_put_contents($this->manifest, json_encode($data));
    expect((new McpParity($this->base))->violations())->toBe([]);
});

test('MCP route coverage reports undeclared mutating routes and accepts MCP or reasoned uiOnly capabilities', function () {
    $this->files->ensureDirectoryExists($this->base.'/app/Modules/Billing/routes');
    file_put_contents($this->base.'/routes/web.php', <<<'PHP'
        <?php

        use App\Http\Controllers\RecordController;
        use Illuminate\Support\Facades\Route;

        Route::post('/records', [RecordController::class, 'store'])->name('records.store');
        Route::middleware('auth')->prefix('admin')->name('admin.')->group(function () {
            Route::controller(RecordController::class)->group(function () {
                Route::delete('/records/{record}', 'destroy')->name('records.destroy');
            });
        });
        Route::patch('/records/{record}/archive', [RecordController::class, 'archive']);
        Route::resource('notes', RecordController::class)->only(['store', 'update']);
        Route::get('/records', [RecordController::class, 'index'])->name('records.index');
        Route::post('/logout', [RecordController::class, 'logout'])->name('logout');
        Route::post('/user/two-factor-authentication', [RecordController::class, 'enable']);
        Route::post('/webhooks/stripe', [RecordController::class, 'hook'])->middleware(VerifyStripeSignature::class);
        Route::post('/webhooks/unsigned', [RecordController::class, 'hook']);
        PHP);
    file_put_contents($this->base.'/app/Modules/Billing/routes/web.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::put('/invoices/{invoice}', [Invoices::class, 'update'])->name('invoices.update');\n");
    $data = json_decode(file_get_contents($this->manifest));
    $data->capabilities[0]->routes = ['records.store', 'patch /records/{record}/archive'];
    $data->capabilities[] = (object) ['id' => 'notes.ui', 'uiOnly' => 'Drag-and-drop note editing has no agent use', 'routes' => ['notes.store']];
    file_put_contents($this->manifest, json_encode($data));
    $check = fn (array $roots = ['routes', 'app/Modules/*/routes'], array $exclusions = McpParity::ROUTE_EXCLUSIONS): array => array_map(fn (array $finding): string => $finding['target'].':'.($finding['line'] ?? '-').' '.$finding['check'], (new McpParity($this->base, routeRoots: $roots, routeExclusions: $exclusions))->violations());

    expect($check())->toBe([
        'app/Modules/Billing/routes/web.php:5 mutating route PUT /invoices/{invoice} (invoices.update) -> Invoices@update has no MCP capability or uiOnly declaration',
        'routes/web.php:9 mutating route DELETE /admin/records/{record} (admin.records.destroy) -> App\Http\Controllers\RecordController@destroy has no MCP capability or uiOnly declaration',
        'routes/web.php:13 mutating route PUT|PATCH /notes/{note} (notes.update) -> App\Http\Controllers\RecordController@update has no MCP capability or uiOnly declaration',
        'routes/web.php:18 mutating route POST /webhooks/unsigned -> App\Http\Controllers\RecordController@hook has no MCP capability or uiOnly declaration',
    ])
        // Configured exclusions replace the defaults: framework routes are then reported like any other.
        ->and($check(['routes'], ['admin.*', 'notes.*', '/webhooks/*']))->toBe([
            'routes/web.php:15 mutating route POST /logout (logout) -> App\Http\Controllers\RecordController@logout has no MCP capability or uiOnly declaration',
            'routes/web.php:16 mutating route POST /user/two-factor-authentication -> App\Http\Controllers\RecordController@enable has no MCP capability or uiOnly declaration',
        ]);

    $data->capabilities[1]->uiOnly = ' ';
    file_put_contents($this->manifest, json_encode($data));
    expect(array_column((new McpParity($this->base, routeRoots: ['routes'], routeExclusions: [...McpParity::ROUTE_EXCLUSIONS, 'admin.*', 'notes.update', '/webhooks/*']))->violations(), 'check'))->toBe(['capability 2 is uiOnly without a reason']);
});

test('MCP route coverage fails closed when routes cannot be enumerated and keeps the missing-manifest finding alone', function () {
    file_put_contents($this->base.'/routes/web.php', "<?php\n\nRoute::post(\$uri, fn () => null);\n");
    expect(array_map(fn (array $finding): string => $finding['target'].':'.($finding['line'] ?? '-').' '.$finding['check'], (new McpParity($this->base))->violations()))
        ->toBe(['routes/web.php:3 mutating routes could not be enumerated: Route::post has a non-literal URI'])
        ->and(array_column((new McpParity($this->base, routeRoots: ['absent']))->violations(), 'check'))->toBe(['mutating routes could not be enumerated: no route files under the configured routeRoots']);
    file_put_contents($this->base.'/routes/web.php', '<?php Route::post(');
    expect(array_column((new McpParity($this->base))->violations(), 'check'))->toBe(['mutating routes could not be enumerated: the route file does not parse']);
    unlink($this->manifest);
    expect(array_column((new McpParity($this->base))->violations(), 'check'))->toBe(['capability inventory is missing or is a symlink']);
});
