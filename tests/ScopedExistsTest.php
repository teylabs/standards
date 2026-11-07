<?php

declare(strict_types=1);

use Tey\Standards\Conformance;
use Tey\Standards\Enforcement\Gate;

/** Rule `tenant.scoped-exists` on a scratch app: a workspace model (BelongsToWorkspace), a plain one, and a workspace table known only from its migration. */
function scopedExistsApp(array $files): string
{
    $models = [
        'Location.php' => "use Illuminate\\Database\\Eloquent\\Model;\nuse Tey\\Kit\\Features\\Workspaces\\Concerns\\BelongsToWorkspace;\n\nfinal class Location extends Model\n{\n    use BelongsToWorkspace;\n}\n",
        'Country.php' => "use Illuminate\\Database\\Eloquent\\Model;\n\nfinal class Country extends Model {}\n",
    ];
    $base = tenantApp([...$models, ...$files]);
    mkdir($base.'/database/migrations', 0777, true);
    file_put_contents($base.'/database/migrations/2026_01_01_000000_create_mailboxes_table.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Schema;\n\nreturn new class {\n    public function up(): void\n    {\n        Schema::create('mailboxes', function (\$table) {\n            \$table->id();\n            \$table->foreignId('workspace_id');\n        });\n    }\n};\n");

    return $base;
}

function scopedExistsRequest(string $rules): string
{
    return "use Illuminate\\Foundation\\Http\\FormRequest;\nuse Illuminate\\Validation\\Rule;\nuse Illuminate\\Validation\\Rules\\Exists;\nuse Tey\\Kit\\Features\\Workspaces\\Models\\Workspace;\n\nfinal class StoreThingRequest extends FormRequest\n{\n    public function rules(): array\n    {\n        return [{$rules}];\n    }\n}\n";
}

function scopedExistsFindings(string $base): array
{
    return array_map(fn (array $v): string => $v['target'].':'.$v['line'].' '.$v['check'], (new Conformance(tenantConfiguration($base)))->violations('tenant.scoped-exists'));
}

test('tenant.scoped-exists flags an unscoped exists rule on a workspace model or table in a FormRequest, with file and line', function () {
    $base = scopedExistsApp(['StoreThingRequest.php' => scopedExistsRequest(implode(",\n", [
        "'location_id' => ['required', Rule::exists(Location::class, 'id')]",
        "'other_id' => [new Exists(Location::class)]",
        "'mailbox_id' => 'required|integer|exists:mailboxes,id'",
        "'nested_id' => [Rule::exists(Location::class)->whereNull('deleted_at')]",
    ]))]);

    expect(scopedExistsFindings($base))->toBe([
        'src/StoreThingRequest.php:14 exists rule on workspace records (FixtureScratch\\Location) is not scoped to the current workspace',
        'src/StoreThingRequest.php:15 exists rule on workspace records (FixtureScratch\\Location) is not scoped to the current workspace',
        'src/StoreThingRequest.php:17 exists rule on workspace records (FixtureScratch\\Location) is not scoped to the current workspace',
        'src/StoreThingRequest.php:16 exists rule on workspace records (mailboxes) is not scoped to the current workspace',
    ])->and(Gate::ADVISORY)->toContain('tenant.scoped-exists');
});

test('tenant.scoped-exists passes a rule scoped to the workspace: a where on the key, a where closure, or the string form naming the key', function () {
    $base = scopedExistsApp(['StoreThingRequest.php' => scopedExistsRequest(implode(",\n", [
        "'location_id' => [Rule::exists(Location::class, 'id')->where('workspace_id', (int) (Workspace::current()?->getKey() ?? 0))]",
        "'other_id' => [Rule::exists(Location::class)->whereNull('deleted_at')->where('workspace_id', 1)]",
        "'closure_id' => [Rule::exists(Location::class)->where(fn (\$query) => \$query->where('workspace_id', 1))]",
        "'mailbox_id' => 'required|exists:mailboxes,id,workspace_id,1'",
    ]))]);

    expect(scopedExistsFindings($base))->toBe([]);
});

test('tenant.scoped-exists ignores models and tables with no workspace, and classes that do not validate input', function () {
    $base = scopedExistsApp([
        'StoreThingRequest.php' => scopedExistsRequest("'country_id' => [Rule::exists(Country::class, 'id')], 'code' => 'exists:countries,code'"),
        'Report.php' => "use Illuminate\\Validation\\Rule;\n\nfinal class Report\n{\n    public function rules(): array\n    {\n        return ['location_id' => [Rule::exists(Location::class)]];\n    }\n}\n",
    ]);

    expect(scopedExistsFindings($base))->toBe([]);
});

test('tenant.scoped-exists checks MCP tools that validate the same way', function () {
    $base = scopedExistsApp(['ReadThingsTool.php' => "use Illuminate\\Validation\\Rule;\nuse Laravel\\Mcp\\Server\\Tool;\n\nfinal class ReadThingsTool extends Tool\n{\n    public function rules(): array\n    {\n        return ['location_id' => [Rule::exists(Location::class)]];\n    }\n}\n"]);

    expect(scopedExistsFindings($base))->toBe(['src/ReadThingsTool.php:12 exists rule on workspace records (FixtureScratch\\Location) is not scoped to the current workspace']);
});

test('tenant.scoped-exists checks nothing in an app with no tenancy', function () {
    $base = scopedExistsApp(['StoreThingRequest.php' => scopedExistsRequest("'location_id' => [Rule::exists(Location::class)]")]);
    file_put_contents($base.'/composer.json', json_encode(['require' => []]));

    expect(scopedExistsFindings($base))->toBe([]);
});
