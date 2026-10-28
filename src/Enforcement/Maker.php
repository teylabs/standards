<?php

declare(strict_types=1);

namespace Tey\Standards\Enforcement;

use Closure;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tey\Standards\Configuration;
use Tey\Standards\Patterns\Compiler;
use Tey\Standards\Patterns\Leaf;
use Tey\Standards\Patterns\PatternCatalog;

/**
 * Emits conformant code for a pattern leaf. Units tey/mod generates (model,
 * policy, job, notification) run its make:* command and then get the
 * standards stamp; units with no mod generator (crud, page, action,
 * capability-tool, settings-section, command) come from recipe stubs, which an app or kit
 * can override with standards.make.stubs. Every file carries the leaf's pattern
 * stamp (a crud resource stamps each file with the leaf it implements); existing
 * files are never overwritten.
 */
final class Maker
{
    /** Unit => the tey/mod generator it delegates to. */
    public const DELEGATED = [
        'model' => 'make:model',
        'policy' => 'make:policy',
        'job' => 'make:job',
        'notification' => 'make:notification',
    ];

    /**
     * @param  array{namespace?: string, app?: string, pages?: string, routes?: string, tests?: string, stubs?: ?string}  $paths
     * @param  list<string>  $generators  make:* commands available through tey/mod; empty when it is not installed
     * @param  (Closure(string, array<string, mixed>): int)|null  $run  runs an artisan command
     */
    public function __construct(
        private readonly string $base,
        private readonly PatternCatalog $catalog,
        private readonly array $paths = [],
        private readonly array $generators = [],
        private readonly ?Closure $run = null,
        private readonly ?bool $kit = null,
        private readonly ?bool $media = null,
    ) {}

    /** Whether a unit and flavor run tey/mod's generator: model --flavor=domain is standards' own. */
    public static function delegates(string $unit, string $flavor): bool
    {
        return isset(self::DELEGATED[$unit]) && ! ($unit === 'model' && $flavor === 'domain');
    }

    /** model --flavor=domain flags; `enum=<field>:<Enum>` may repeat. */
    public const MODEL_OPTIONS = ['tenant', 'soft-deletes', 'media', 'sortable'];

    /** crud options: the flags that add to the resource. */
    public const CRUD_OPTIONS = ['bulk', 'mcp', 'soft-deletes'];

    /**
     * @param  list<string>  $options  crud only: any of CRUD_OPTIONS
     * @return list<string> the project-relative files written or changed
     */
    public function make(string $unit, string $name, string $flavor, ?string $leafId = null, array $options = []): array
    {
        if (! in_array($flavor, Compiler::UNITS[$unit] ?? [], true)) {
            throw new InvalidArgumentException("Unknown unit or flavor {$unit} --flavor={$flavor}. Units: ".implode('; ', array_map(fn (string $u, array $f): string => $u.' ('.implode('|', $f).')', array_keys(Compiler::UNITS), Compiler::UNITS)).'.');
        }

        if (preg_match('/^[A-Z][A-Za-z0-9]*$/D', $name) !== 1) {
            throw new InvalidArgumentException('Name must be StudlyCase, for example Invoices.');
        }

        $leaf = $leafId ?? $this->leaf($unit, $flavor);

        if ($leafId !== null) {
            $node = $this->catalog->node($leafId);

            if (! $node instanceof Leaf || $node->make !== "standards:make {$unit} --flavor={$flavor}") {
                throw new InvalidArgumentException("Leaf [{$leafId}] is not scaffolded by standards:make {$unit} --flavor={$flavor}.");
            }
        }

        $allowed = match (true) {
            $unit === 'crud' => self::CRUD_OPTIONS,
            $unit === 'model' && $flavor === 'domain' => self::MODEL_OPTIONS,
            default => [],
        };
        $unknown = array_values(array_filter($options, fn (string $option): bool => ! in_array($option, $allowed, true) && ! ($unit === 'model' && $flavor === 'domain' && str_starts_with($option, 'enum='))));

        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown option --'.implode(', --', $unknown).' for '.$unit.($unit === 'model' ? ' --flavor='.$flavor : '').'.');
        }

        if ($unit === 'model' && $flavor === 'domain') {
            return $this->emitModel($name, $options, $leaf);
        }

        if (isset(self::DELEGATED[$unit])) {
            return $this->delegate(self::DELEGATED[$unit], $name, $leaf);
        }

        if ($unit === 'crud') {
            $this->requireKit('crud');

            return $this->crud($name, $options);
        }

        if ($unit === 'webhook') {
            return $this->webhook($name, $leaf);
        }

        if ($unit === 'api-client') {
            return $this->apiClient($name, $flavor, $leaf);
        }
        $app = $this->paths['app'] ?? 'app';
        $pages = $this->paths['pages'] ?? 'resources/js/pages';
        $tests = $this->paths['tests'] ?? 'tests/Feature';
        $slug = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $name));
        $values = [
            'namespace' => $this->paths['namespace'] ?? 'App',
            'name' => $name,
            'slug' => $slug,
            'title' => ucfirst(str_replace('-', ' ', $slug)),
            'leaf' => $leaf,
            'flavor' => $flavor,
            'action' => $name,
            'test_suffix' => '',
            'capability' => str_replace('-', '_', $slug).'.'.($flavor === 'write' ? 'update' : 'read'),
            'capability_group' => str_replace('-', '_', $slug),
            'tool_rules' => $flavor === 'write' ? "'id' => ['required', 'integer'], 'intent_key' => ['sometimes', 'string', 'max:120']" : '',
            'rules' => $flavor === 'toggles' ? "'updates' => ['sometimes', 'boolean']" : "'name' => ['required', 'string', 'max:255']",
        ];

        [$files, $route] = match ($unit) {
            'page' => $this->page($flavor, $name, $slug, $app, $pages, $tests, $values),
            'action' => [[
                "{$app}/Actions/{$name}.php" => 'action.stub',
                "{$tests}/Actions/{$name}Test.php" => 'action.test.stub',
            ], null],
            // An operator command on Tey\Standards\Operator (dry run unless --apply, idempotent, refused in production unless forced) and the test that proves it.
            // The class is suffixed Command (Laravel's preset expects it); the artisan name and the table come from the name without it.
            'command' => (function () use ($app, $tests, $flavor, $name): array {
                $base = Str::beforeLast(Str::finish($name, 'Command'), 'Command');
                $values = ['name' => $base.'Command', 'slug' => Str::kebab($base), 'table' => Str::snake($base), 'column' => $flavor === 'maintenance' ? 'created_at' : 'processed_at'];

                return [[
                    "{$app}/Console/Commands/{$base}Command.php" => ["command/{$flavor}.stub", $values],
                    "{$tests}/Console/{$base}CommandTest.php" => ["command/{$flavor}.test.stub", $values],
                ], null];
            })(),
            'capability-tool' => [[
                "{$app}/Mcp/Tools/{$name}Tool.php" => 'capability-tool.stub',
                "{$tests}/Mcp/{$name}ToolTest.php" => 'capability-tool.test.stub',
            ], null],
            'settings-section' => [[
                "{$app}/Http/Controllers/Settings/{$name}Controller.php" => 'settings.controller.stub',
                "{$app}/Http/Requests/Update{$name}SettingsRequest.php" => 'settings.request.stub',
                "{$app}/Actions/Update{$name}Settings.php" => ['action.stub', ['action' => "Update{$name}Settings"]],
                "{$pages}/settings/{$name}/Page.vue" => "settings.{$flavor}.vue.stub",
                "{$tests}/Settings/{$name}Test.php" => 'settings.test.stub',
            ], self::group("{$values['namespace']}\\Http\\Controllers\\Settings\\{$name}Controller", ["Route::get('/settings/{$slug}', 'edit')->name('settings.{$slug}.edit');", "Route::patch('/settings/{$slug}', 'update')->name('settings.{$slug}.update');"])],
        };

        $written = $this->emit($files, $values);

        if ($route !== null) {
            $routes = $this->paths['routes'] ?? 'routes/web.php';
            $file = $this->base.'/'.$routes;
            $current = is_file($file) ? (string) file_get_contents($file) : "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n";
            $this->write($routes, rtrim($current)."\n\n// standards-pattern: {$leaf}\n{$route}\n");
            $written[] = $routes;
        }

        return $written;
    }

    /**
     * Refuses before writing anything if any target exists, then renders and writes each file.
     *
     * @param  array<string, string|array{0: string, 1: array<string, string>}>  $files
     * @param  array<string, string>  $values
     * @return list<string>
     */
    private function emit(array $files, array $values): array
    {
        foreach ($files as $target => $stub) {
            if (is_file($this->base.'/'.$target)) {
                throw new InvalidArgumentException("{$target} already exists; standards:make never overwrites.");
            }
        }

        $written = [];

        foreach ($files as $target => $stub) {
            [$stub, $overrides] = is_array($stub) ? $stub : [$stub, []];
            $contents = rtrim($this->render($stub, [...$values, ...$overrides]))."\n";
            $contents = str_ends_with($target, 'Test.php') ? $this->covered($target, $contents) : $contents;
            // Names that come from the unit's name sort differently per name, so the import block is sorted after rendering.
            $this->write($target, str_ends_with($target, '.php') ? self::imports($contents) : $contents);
            $written[] = $target;
        }

        return $written;
    }

    /** @param  list<string>  $options */
    private function emitModel(string $name, array $options, string $leaf): array
    {
        [$files, $values] = $this->domainModel($name, $options, $leaf);

        return [...$this->emit($files, $values), ...$this->factoryAutoload()];
    }

    /**
     * The generated factory lives in `Database\Factories` (database/factories), where Eloquent looks for it. An app whose composer.json does not map that
     * namespace gets the mapping added (composer.json is then listed as changed; run composer dump-autoload). Any other autoload entry is kept as it is.
     *
     * @return list<string>
     */
    private function factoryAutoload(): array
    {
        $file = $this->base.'/composer.json';
        if (! is_file($file)) {
            return [];
        }
        $composer = json_decode((string) file_get_contents($file), true);
        if (! is_array($composer) || isset($composer['autoload']['psr-4']['Database\\Factories\\'])) {
            return [];
        }
        $composer['autoload']['psr-4']['Database\\Factories\\'] = 'database/factories/';
        file_put_contents($file, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        return ['composer.json'];
    }

    /**
     * A domain model and everything that makes it safe to use: the model, its migration (indexes, enum check constraints, a constrained workspace with an
     * explicit on-delete, lock_version), a factory, an enum class per `enum=<field>:<Enum>`, and tests on the app's own database. `tenant` makes it
     * workspace-owned through tey/kit's BelongsToWorkspace and GuardsStaleWrites, with a policy; `soft-deletes`, `media` (spatie/laravel-medialibrary)
     * and `sortable` (a `position`) add to it. crud builds its model through here, so there is one implementation.
     *
     * @param  list<string>  $options
     * @return array{0: array<string, string|array{0: string, 1: array<string, string>}>, 1: array<string, string>}
     */
    private function domainModel(string $name, array $options, string $leaf, bool $withTests = true): array
    {
        $tenant = in_array('tenant', $options, true);
        $soft = in_array('soft-deletes', $options, true);
        $media = in_array('media', $options, true);
        $sortable = in_array('sortable', $options, true);
        if ($tenant) {
            $this->requireKit('model --tenant');
        }
        if ($media && ! ($this->media ?? interface_exists('Spatie\\MediaLibrary\\HasMedia'))) {
            throw new InvalidArgumentException('--media needs spatie/laravel-medialibrary; install it (composer require spatie/laravel-medialibrary) and try again.');
        }
        $enums = [];
        foreach ($options as $option) {
            if (! str_starts_with($option, 'enum=')) {
                continue;
            }
            if (preg_match('/^enum=([a-z][a-z0-9_]*):([A-Z][A-Za-z0-9]*)$/D', $option, $match) !== 1) {
                throw new InvalidArgumentException("--{$option} must be --enum=<field>:<Enum>, for example --enum=status:InvoiceStatus.");
            }
            if (isset($enums[$match[1]]) || in_array($match[1], ['id', 'name', 'workspace_id', 'lock_version', 'position'], true)) {
                throw new InvalidArgumentException("--enum field {$match[1]} is repeated or reserved.");
            }
            $enums[$match[1]] = $match[2];
        }

        $app = $this->paths['app'] ?? 'app';
        $tests = $this->paths['tests'] ?? 'tests/Feature';
        $namespace = $this->paths['namespace'] ?? 'App';
        $plural = Str::plural($name);
        $table = Str::snake($plural);
        $title = ucfirst(str_replace('-', ' ', Str::kebab($name)));
        $titles = ucfirst(str_replace('-', ' ', Str::kebab($plural)));
        $migration = date('Y_m_d_His').'_create_'.$table.'_table.php';

        $imports = ['Illuminate\\Database\\Eloquent\\Factories\\HasFactory', 'Illuminate\\Database\\Eloquent\\Model'];
        $traits = ['HasFactory'];
        $implements = '';
        $guarded = ["'id'", "'lock_version'"];
        $casts = ["'lock_version' => 'integer'"];
        $methods = [];
        $doc = $tenant ? "A {$title}, owned by one workspace: read and written only inside it (BelongsToWorkspace), and edited through updateIfCurrent() so a stale write is refused (GuardsStaleWrites)." : "A {$title}. `lock_version` counts its edits: an edit that sends back an older one must be refused.";
        if ($tenant) {
            array_push($imports, 'Tey\\Kit\\Features\\Workspaces\\Concerns\\BelongsToWorkspace', 'Tey\\Kit\\Features\\Workspaces\\Concerns\\GuardsStaleWrites');
            array_push($traits, 'BelongsToWorkspace', 'GuardsStaleWrites');
            $guarded[] = "'workspace_id'";
            $casts[] = "'workspace_id' => 'integer'";
        }
        if ($soft) {
            $imports[] = 'Illuminate\\Database\\Eloquent\\SoftDeletes';
            $traits[] = 'SoftDeletes';
        }
        if ($media) {
            array_push($imports, 'Spatie\\MediaLibrary\\HasMedia', 'Spatie\\MediaLibrary\\InteractsWithMedia');
            $traits[] = 'InteractsWithMedia';
            $implements = ' implements HasMedia';
            $methods[] = "\n    /** Files on a {$title}: one collection, `attachments`, on the default disk. Serve them only to someone who may view the {$title}. */\n    public function registerMediaCollections(): void\n    {\n        \$this->addMediaCollection('attachments');\n    }\n";
        }
        if ($sortable) {
            $imports[] = 'Illuminate\\Database\\Eloquent\\Builder';
            $casts[] = "'position' => 'integer'";
            $guarded[] = "'position'";
            $methods[] = "\n    protected static function booted(): void\n    {\n        // A new row goes last".($tenant ? ' in its workspace' : '').".\n        self::creating(fn (self \$model) => \$model->position = (int) self::query()->max('position') + 1);\n    }\n\n    /** @param  Builder<self>  \$query */\n    public function scopeOrdered(Builder \$query): void\n    {\n        \$query->orderBy('position')->orderBy('id');\n    }\n";
        }
        $columns = ['            $table->id();'];
        if ($tenant) {
            $columns[] = '            // The owning workspace: a workspace with '.strtolower($titles).' cannot be deleted until they are.';
            $columns[] = '            $table->foreignId(\'workspace_id\')->constrained()->restrictOnDelete();';
        }
        $columns[] = '            $table->string(\'name\');';
        $factory = [];
        $factoryImports = [];
        $enumFiles = [];
        foreach ($enums as $field => $enum) {
            $imports[] = "{$namespace}\\Enums\\{$enum}";
            $casts[] = "'{$field}' => {$enum}::class";
            $columns[] = "            // {$enum}'s values; the database refuses any other (a check constraint on SQLite and PostgreSQL).";
            $columns[] = "            \$table->enum('{$field}', ['draft', 'active', 'archived'])->default('draft');";
            $factoryImports[] = "use {$namespace}\\Enums\\{$enum};";
            $factory[] = "            '{$field}' => {$enum}::Draft,";
            $enumFiles["{$app}/Enums/{$enum}.php"] = ['model/enum.stub', ['enum' => $enum, 'enum_field' => $field]];
        }
        if ($sortable) {
            $columns[] = '            $table->unsignedInteger(\'position\')->default(0);';
        }
        $columns[] = '            $table->unsignedInteger(\'lock_version\')->default(1);';
        $columns[] = '            $table->timestamps();';
        if ($soft) {
            $columns[] = '            $table->softDeletes();';
        }
        $scope = $tenant ? "'workspace_id', " : '';
        if ($tenant) {
            $columns[] = '            // The target of a composite foreign key from a child table, which keeps a child in its parent\'s workspace.';
            $columns[] = '            $table->unique([\'workspace_id\', \'id\']);';
        }
        $columns[] = "            \$table->index([{$scope}'name']);";
        foreach (array_keys($enums) as $field) {
            $columns[] = "            \$table->index([{$scope}'{$field}']);";
        }
        if ($sortable) {
            $columns[] = "            \$table->index([{$scope}'position']);";
        }
        sort($imports);
        // Pint's ordered_traits.
        sort($traits);
        $values = [
            'namespace' => $namespace, 'name' => $name, 'plural' => $plural, 'table' => $table, 'perm' => $table, 'var' => Str::camel($name), 'slug' => Str::kebab($plural),
            'title' => $title, 'title_lower' => strtolower($title), 'titles' => $titles, 'titles_lower' => strtolower($titles), 'leaf' => $leaf, 'migration' => $migration,
            'model_imports' => implode('', array_map(fn (string $class): string => "use {$class};\n", array_unique($imports))),
            'model_doc' => $doc, 'model_implements' => $implements, 'model_traits' => implode(', ', $traits), 'model_guarded' => implode(', ', $guarded),
            'model_casts' => implode(', ', $casts), 'model_methods' => implode('', $methods),
            'migration_columns' => implode("\n", $columns)."\n",
            'factory_imports' => implode("\n", $factoryImports).($factoryImports === [] ? '' : "\n"),
            'factory_doc' => $tenant ? ' Create them inside a workspace (TenantBoundary): the model takes the current one.' : '',
            'factory_fields' => implode("\n", $factory).($factory === [] ? '' : "\n"),
        ];
        $enumTests = '';
        foreach ($enums as $field => $enum) {
            $enumTests .= $this->render($tenant ? 'model/test-enum-tenant.stub' : 'model/test-enum.stub', [...$values, 'enum' => $enum, 'enum_field' => $field]);
        }
        $values['enum_tests'] = $enumTests;
        $testImports = ["{$namespace}\\Models\\{$name}", 'Illuminate\\Support\\Facades\\Artisan', ...array_map(fn (string $enum): string => "{$namespace}\\Enums\\{$enum}", array_values($enums))];
        if ($tenant) {
            array_push($testImports, 'Illuminate\\Database\\QueryException', 'Illuminate\\Support\\Facades\\DB', 'Illuminate\\Support\\Facades\\Hash', 'Illuminate\\Support\\Str', 'Tey\\Concierge\\Actions\\EstablishWorkspace', 'Tey\\Kit\\Features\\Workspaces\\Models\\Workspace', 'Tey\\Kit\\Features\\Workspaces\\TenantBoundary');
        } elseif ($enums !== []) {
            array_push($testImports, 'Illuminate\\Database\\QueryException', 'Illuminate\\Support\\Facades\\DB');
        }
        usort($testImports, fn (string $a, string $b): int => strcasecmp(str_replace('\\', ' ', $a), str_replace('\\', ' ', $b)));
        $values['test_imports'] = implode('', array_map(fn (string $class): string => "use {$class};\n", array_unique($testImports)));
        $files = [
            "{$app}/Models/{$name}.php" => 'model/model.stub',
            "database/migrations/{$migration}" => 'model/migration.stub',
            "database/factories/{$name}Factory.php" => 'model/factory.stub',
            ...$enumFiles,
        ];
        if ($tenant) {
            $files["{$app}/Policies/{$name}Policy.php"] = ['crud/policy.stub', ['leaf' => $leaf]];
        }
        if ($withTests) {
            $files["{$tests}/{$plural}/{$name}ModelTest.php"] = $tenant ? 'model/test-tenant.stub' : 'model/test.stub';
        }

        return [$files, $values];
    }

    /** Workspace tenancy is tey/kit's: without it, refuse rather than emit code that cannot load. */
    private function requireKit(string $what): void
    {
        if (! ($this->kit ?? trait_exists('Tey\\Kit\\Features\\Workspaces\\Concerns\\BelongsToWorkspace'))) {
            throw new InvalidArgumentException("{$what} needs tey/kit 0.4.15 or later (its Workspaces feature owns workspace tenancy). Install tey/kit and adopt workspaces, or leave out --tenant for a model with no workspace.");
        }
    }

    /**
     * A whole CRUD resource for one record type, owned by a workspace: model and migration, policy, the kit wiring (capability, permissions, routes,
     * navigation), a CRUDDY controller, form requests, one Action per write (principal first, the update guarded by `lock_version`), typed ViewModels,
     * the Index, Form and Show pages, and feature tests. Each file is stamped with the leaf it implements. `bulk` adds selectable rows and a bulk delete,
     * `mcp` a read and a write capability tool, `soft-deletes` soft deletes and a restoration.
     *
     * @param  list<string>  $options
     * @return list<string>
     */
    private function crud(string $name, array $options): array
    {
        $app = $this->paths['app'] ?? 'app';
        $pages = $this->paths['pages'] ?? 'resources/js/pages';
        $tests = $this->paths['tests'] ?? 'tests/Feature';
        $namespace = $this->paths['namespace'] ?? 'App';
        $plural = Str::plural($name);
        $slug = Str::kebab($plural);
        $table = Str::snake($plural);
        $title = ucfirst(str_replace('-', ' ', Str::kebab($name)));
        $titles = ucfirst(str_replace('-', ' ', $slug));
        [$viewModel, $viewModels] = $this->viewModels($app, $namespace);
        $bulk = in_array('bulk', $options, true);
        $soft = in_array('soft-deletes', $options, true);
        // The model is the domain-model generator's (workspace-owned, stale-write guarded), so crud and model share one implementation.
        [$modelFiles, $modelValues] = $this->domainModel($name, $soft ? ['tenant', 'soft-deletes'] : ['tenant'], 'data-actions.domain-model');
        $modelFiles = array_map(fn (string|array $stub): array => is_array($stub) ? [$stub[0], ['leaf' => 'data-actions.domain-model', ...$stub[1]]] : [$stub, ['leaf' => 'data-actions.domain-model']], $modelFiles);
        $migration = $modelValues['migration'];
        $values = [...$modelValues,
            'namespace' => $namespace, 'name' => $name, 'plural' => $plural, 'slug' => $slug, 'table' => $table, 'perm' => $table,
            'var' => Str::camel($name), 'title' => $title, 'title_lower' => strtolower($title), 'titles' => $titles, 'titles_lower' => strtolower($titles),
            'view_model' => ltrim($viewModel, '\\'), 'view_model_base' => class_basename($viewModel), 'vm_namespace' => $viewModels[1].'\\'.$plural, 'migration' => $migration, 'leaf' => 'ui-page.crud-resource',
            'bulk_table' => $bulk ? "            ->bulk(new Action('Delete', WorkspaceUrl::to(\$this->workspace, '{$slug}/selection'), 'delete', confirm: ['title' => 'Delete the selected ".strtolower($titles)."?', 'description' => 'Every selected ".strtolower($title)." will be deleted.', 'label' => 'Delete'], key: 'delete', destructive: true))\n" : '',
            'extra_routes' => '',
            'bulk_test' => '',
        ];
        $uses = [];
        if ($bulk) {
            $uses[] = "{$namespace}\\Http\\Controllers\\{$name}SelectionController";
            $values['extra_routes'] .= "    // Before `{id}`: the selection is its own resource.\n    Route::controller({$name}SelectionController::class)->prefix('{$slug}/selection')->group(function (): void {\n        Route::delete('', 'destroy');\n    });\n";
            $values['bulk_test'] = $this->render('crud/bulk-test.stub', $values);
        }
        if ($soft) {
            $uses[] = "{$namespace}\\Http\\Controllers\\{$name}RestorationController";
            $values['extra_routes'] .= "    Route::controller({$name}RestorationController::class)->prefix('{$slug}/{id}/restoration')->whereNumber('id')->group(function (): void {\n        Route::post('', 'store');\n    });\n";
        }
        $form = ['leaf' => 'ui-page.form'];
        $files = [
            ...$modelFiles,
            "{$app}/Providers/{$name}ServiceProvider.php" => 'crud/provider.stub',
            "routes/{$slug}.php" => 'crud/routes.stub',
            "{$app}/Http/Controllers/{$name}Controller.php" => 'crud/controller.stub',
            "{$app}/Http/Requests/Store{$name}Request.php" => ['crud/store-request.stub', $form],
            "{$app}/Http/Requests/Update{$name}Request.php" => ['crud/update-request.stub', $form],
            "{$app}/Actions/Create{$name}.php" => ['crud/create-action.stub', ['leaf' => 'data-actions.action']],
            "{$app}/Actions/Update{$name}.php" => ['crud/update-action.stub', ['leaf' => 'data-actions.action']],
            "{$app}/Actions/Delete{$name}.php" => ['crud/delete-action.stub', ['leaf' => 'data-actions.action']],
            "{$viewModels[0]}/{$plural}/{$name}IndexViewModel.php" => ['crud/index-view-model.stub', ['leaf' => 'ui-page.index']],
            "{$viewModels[0]}/{$plural}/{$name}FormViewModel.php" => ['crud/form-view-model.stub', $form],
            "{$viewModels[0]}/{$plural}/{$name}ShowViewModel.php" => ['crud/show-view-model.stub', ['leaf' => 'ui-page.show']],
            "{$pages}/{$plural}/Index/Page.vue" => ['crud/index.vue.stub', ['leaf' => $bulk ? 'ui-page.data-table' : 'ui-page.index']],
            "{$pages}/{$plural}/Form/Page.vue" => ['crud/form.vue.stub', $form],
            "{$pages}/{$plural}/Show/Page.vue" => ['crud/show.vue.stub', ['leaf' => 'ui-page.show']],
            "{$tests}/{$plural}/{$name}Test.php" => 'crud/test.stub',
        ];
        if ($bulk) {
            $files["{$app}/Http/Controllers/{$name}SelectionController.php"] = ['crud/selection-controller.stub', ['leaf' => 'ui-page.data-table']];
        }
        if ($soft) {
            $files["{$app}/Http/Controllers/{$name}RestorationController.php"] = 'crud/restoration-controller.stub';
            $files["{$app}/Actions/Restore{$name}.php"] = ['crud/restore-action.stub', ['leaf' => 'data-actions.action']];
        }
        if (in_array('mcp', $options, true)) {
            $tool = ['flavor' => 'read', 'tool_rules' => ''];
            $files["{$app}/Mcp/Tools/Read{$plural}Tool.php"] = ['capability-tool.stub', [...$tool, 'name' => "Read{$plural}", 'title' => 'Read '.strtolower($titles), 'capability' => "{$table}.read", 'leaf' => 'mcp.read']];
            $files["{$tests}/Mcp/Read{$plural}ToolTest.php"] = ['capability-tool.test.stub', [...$tool, 'name' => "Read{$plural}", 'capability' => "{$table}.read", 'leaf' => 'mcp.read']];
            $write = ['flavor' => 'write', 'tool_rules' => "'id' => ['required', 'integer'], 'name' => ['required', 'string', 'max:255'], 'lock_version' => ['required', 'integer', 'min:1'], 'intent_key' => ['sometimes', 'string', 'max:120']"];
            $files["{$app}/Mcp/Tools/Update{$name}Tool.php"] = ['capability-tool.stub', [...$write, 'name' => "Update{$name}", 'title' => 'Update a '.strtolower($title), 'capability' => "{$table}.write", 'leaf' => 'mcp.write']];
            $files["{$tests}/Mcp/Update{$name}ToolTest.php"] = ['capability-tool.test.stub', [...$write, 'name' => "Update{$name}", 'capability' => "{$table}.write", 'leaf' => 'mcp.write']];
        }
        $values['route_uses'] = implode('', array_map(fn (string $class): string => "use {$class};\n", $uses));
        $written = $this->emit($files, $values);

        $written[] = $this->provide("{$namespace}\\Providers\\{$name}ServiceProvider", 'ui-page.crud-resource');
        $written = [...$written, ...$this->factoryAutoload()];

        return $written;
    }

    /**
     * The file with its top-level `use` block holding the given classes too, sorted as Pint's ordered_imports wants and followed by one blank line.
     * A file with no block gets one after its opening comment.
     *
     * @param  list<string>  $classes
     */
    private static function imports(string $contents, array $classes = []): string
    {
        if (preg_match('/^((?:use [^;\n]+;\n)+)/m', $contents, $block, PREG_OFFSET_CAPTURE) === 1) {
            [$lines, $at] = $block[1];
        } else {
            $lines = '';
            $at = preg_match('/\A<\?php\n(?:\n?(?:\/\/[^\n]*\n)+)?\n?/', $contents, $head) === 1 ? strlen($head[0]) : 0;
            if ($classes === []) {
                return $contents;
            }
        }
        $imports = array_values(array_unique([...array_filter(explode("\n", $lines)), ...array_map(fn (string $class): string => 'use '.$class.';', $classes)]));
        usort($imports, fn (string $a, string $b): int => strcasecmp(str_replace('\\', ' ', $a), str_replace('\\', ' ', $b)));
        $rest = ltrim(substr($contents, $at + strlen($lines)), "\n");

        return rtrim(substr($contents, 0, $at), "\n").($at > 0 ? "\n\n" : '').implode("\n", $imports)."\n\n".$rest;
    }

    /** Adds a provider to bootstrap/providers.php, stamped; one already listed is left as it is. */
    private function provide(string $class, string $leaf): string
    {
        $providers = 'bootstrap/providers.php';
        $file = $this->base.'/'.$providers;
        $current = is_file($file) ? (string) file_get_contents($file) : "<?php\n\nreturn [\n];\n";
        if (str_contains($current, $class.'::class')) {
            return $providers;
        }
        $at = strrpos($current, '];');
        if ($at === false) {
            throw new InvalidArgumentException("{$providers} does not return a list; add \\{$class}::class to it yourself.");
        }
        // A list written with imports gets an import and the short name, as Pint wants; otherwise the full name.
        $imported = preg_match('/^use [^;\n]+;$/m', $current) === 1;
        $entry = "    // standards-pattern: {$leaf}\n    ".($imported ? class_basename($class) : '\\'.$class)."::class,\n";
        $updated = substr($current, 0, $at).$entry.substr($current, $at);
        $this->write($providers, $imported ? self::imports($updated, [$class]) : $updated);

        return $providers;
    }

    /**
     * An inbound webhook from a provider on spatie/laravel-webhook-client: its config entry, signature validator (HMAC over the raw body, constant-time,
     * a timestamp tolerance), profile, queued job with a per-event handler map (idempotent by event id) and an example handler, the route, and the test
     * that proves it. The first webhook also adds what every webhook shares: the stored-call model with its unique event id, the receiving
     * controller (403 on a bad signature), the migration, routes/webhooks.php and the provider that loads it outside the web group under its own rate
     * limit.
     *
     * @return list<string>
     */
    private function webhook(string $name, string $leaf): array
    {
        if (! class_exists('Spatie\\WebhookClient\\WebhookProcessor') && ! is_dir($this->base.'/vendor/spatie/laravel-webhook-client')) {
            throw new InvalidArgumentException('Webhooks are received with spatie/laravel-webhook-client: composer require spatie/laravel-webhook-client, then run this again.');
        }
        $app = $this->paths['app'] ?? 'app';
        $tests = $this->paths['tests'] ?? 'tests/Feature';
        $namespace = $this->paths['namespace'] ?? 'App';
        $slug = Str::kebab($name);
        $values = [
            'namespace' => $namespace, 'name' => $name, 'slug' => $slug, 'slug_snake' => Str::snake($name), 'title' => Str::headline($name), 'leaf' => $leaf,
            'env_prefix' => strtoupper(Str::snake($name)), 'signature_header' => 'X-'.$name.'-Signature', 'timestamp_header' => 'X-'.$name.'-Timestamp',
            'example_event' => $slug.'.updated', 'example_class' => $name.'Updated',
        ];
        // A webhook arrives with no tenant: in a multitenant app its job must not wait for one (the handler finds the tenant from the payload).
        $tenancy = interface_exists('Spatie\\Multitenancy\\Jobs\\NotTenantAware') || is_dir($this->base.'/vendor/spatie/laravel-multitenancy');
        $values += $tenancy
            ? ['tenant_use' => "use Spatie\\Multitenancy\\Jobs\\NotTenantAware;\n", 'tenant_implements' => ' implements NotTenantAware', 'tenant_note' => "\n *\n * Not tenant-aware: a webhook arrives with no tenant, so a handler that needs one finds it from the payload and runs inside it."]
            : ['tenant_use' => '', 'tenant_implements' => '', 'tenant_note' => ''];
        $config = $this->base.'/config/webhook-client.php';
        if (is_file($config) && preg_match("/'name'\\s*=>\\s*'".preg_quote($slug, '/')."'/", (string) file_get_contents($config)) === 1) {
            throw new InvalidArgumentException("config/webhook-client.php already has a {$slug} webhook; standards:make never overwrites.");
        }
        $files = [
            "{$app}/Webhooks/{$name}/{$name}SignatureValidator.php" => 'webhook/signature-validator.stub',
            "{$app}/Webhooks/{$name}/{$name}WebhookProfile.php" => 'webhook/profile.stub',
            "{$app}/Webhooks/{$name}/Process{$name}Webhook.php" => 'webhook/job.stub',
            "{$app}/Webhooks/{$name}/Handlers/Handle{$name}Updated.php" => 'webhook/handler.stub',
            "{$tests}/Webhooks/{$name}WebhookTest.php" => 'webhook/test.stub',
        ];
        $shared = [
            "{$app}/Models/WebhookCall.php" => 'webhook/webhook-call.stub',
            "{$app}/Webhooks/ReceiveWebhook.php" => 'webhook/controller.stub',
            "{$app}/Providers/WebhookServiceProvider.php" => 'webhook/provider.stub',
            'routes/webhooks.php' => 'webhook/routes.stub',
        ];
        if (glob($this->base.'/database/migrations/*_add_event_id_to_webhook_calls_table.php') === []) {
            $shared['database/migrations/'.date('Y_m_d_His').'_add_event_id_to_webhook_calls_table.php'] = 'webhook/migration.stub';
        }
        $written = $this->emit($files + array_filter($shared, fn (string $stub, string $target): bool => ! is_file($this->base.'/'.$target), ARRAY_FILTER_USE_BOTH), $values);

        $current = is_file($config) ? (string) file_get_contents($config) : $this->render('webhook/config.stub', $values);
        $at = strpos($current, "'configs' => [");
        if ($at === false) {
            throw new InvalidArgumentException("config/webhook-client.php has no 'configs' => [ list; add the {$slug} entry yourself.");
        }
        $at += strlen("'configs' => [");
        $merged = substr($current, 0, $at)."\n".rtrim($this->render('webhook/config-entry.stub', $values), "\n").substr($current, $at);
        $this->write('config/webhook-client.php', self::imports($merged, ["{$namespace}\\Models\\WebhookCall", "{$namespace}\\Webhooks\\{$name}\\{$name}SignatureValidator", "{$namespace}\\Webhooks\\{$name}\\{$name}WebhookProfile", "{$namespace}\\Webhooks\\{$name}\\Process{$name}Webhook"]));
        $written[] = 'config/webhook-client.php';
        $routes = (string) file_get_contents($this->base.'/routes/webhooks.php');
        $this->write('routes/webhooks.php', rtrim($routes)."\n\n// standards-pattern: {$leaf}\nRoute::post('webhooks/{$slug}', ReceiveWebhook::class)->defaults('webhook', '{$slug}')->name('webhooks.{$slug}');\n");
        $written[] = 'routes/webhooks.php';
        $written[] = $this->env(["{$values['env_prefix']}_WEBHOOK_SECRET="], $leaf);
        $written[] = $this->provide("{$namespace}\\Providers\\WebhookServiceProvider", $leaf);

        return array_values(array_unique($written));
    }

    /**
     * A client for another service's API, SDK-first: the contract the app depends on, the real client (the official SDK behind it for `sdk`, Laravel's
     * Http with a timeout, retries and backoff through a macro for `http`), a Fake for tests, its provider, config/services.php keys and .env.example
     * lines, and tests that never reach the service.
     *
     * @return list<string>
     */
    private function apiClient(string $name, string $flavor, string $leaf): array
    {
        $app = $this->paths['app'] ?? 'app';
        $tests = $this->paths['tests'] ?? 'tests/Feature';
        $namespace = $this->paths['namespace'] ?? 'App';
        $slug = Str::snake($name);
        $prefix = strtoupper($slug);
        $impl = ($flavor === 'sdk' ? 'Sdk' : 'Http').$name.'Client';
        $values = [
            'namespace' => $namespace, 'name' => $name, 'slug' => $slug, 'title' => Str::headline($name), 'leaf' => $leaf, 'impl' => $impl, 'macro' => Str::camel($name),
            'sdk_class' => 'Vendor\\'.$name.'\\Client', 'provider_note' => $flavor === 'sdk' ? ', its official SDK' : ', over HTTP through the `'.Str::camel($name).'` macro',
        ];
        $values['provider_uses'] = implode("\n", $flavor === 'sdk'
            ? ["use {$namespace}\\Services\\{$name}\\{$name}Client;", "use {$namespace}\\Services\\{$name}\\{$impl};", 'use Illuminate\\Support\\ServiceProvider;']
            : ["use {$namespace}\\Services\\{$name}\\{$name}Client;", "use {$namespace}\\Services\\{$name}\\{$impl};", 'use Illuminate\\Http\\Client\\ConnectionException;', 'use Illuminate\\Http\\Client\\RequestException;', 'use Illuminate\\Support\\Facades\\Http;', 'use Illuminate\\Support\\ServiceProvider;', 'use Throwable;'])."\n";
        $values['provider_body'] = rtrim($this->render('api-client/provider-'.$flavor.'.part', $values), "\n");
        $files = [
            "{$app}/Services/{$name}/{$name}Client.php" => 'api-client/contract.stub',
            "{$app}/Exceptions/{$name}Exception.php" => 'api-client/exception.stub',
            "{$app}/Services/{$name}/{$impl}.php" => 'api-client/'.$flavor.'-client.stub',
            "{$app}/Services/{$name}/Fake{$name}Client.php" => 'api-client/fake.stub',
            "{$app}/Providers/{$name}ServiceProvider.php" => 'api-client/provider.stub',
            "{$tests}/Services/{$name}ClientTest.php" => 'api-client/test.stub',
        ];
        if ($flavor === 'http') {
            $files["{$tests}/Services/Http{$name}ClientTest.php"] = 'api-client/http.test.stub';
        }
        $written = $this->emit($files, $values);
        $keys = $flavor === 'sdk'
            ? ["'key' => env('{$prefix}_KEY')"]
            : ["'base_url' => env('{$prefix}_BASE_URL')", "'token' => env('{$prefix}_TOKEN')", "'timeout' => (int) env('{$prefix}_TIMEOUT', 10)"];
        $written[] = $this->services($slug, $keys, $leaf);
        $written[] = $this->env($flavor === 'sdk' ? ["{$prefix}_KEY="] : ["{$prefix}_BASE_URL=", "{$prefix}_TOKEN=", "{$prefix}_TIMEOUT=10"], $leaf);
        $written[] = $this->provide("{$namespace}\\Providers\\{$name}ServiceProvider", $leaf);

        return array_values(array_unique($written));
    }

    /** Adds a `services.<slug>` entry to config/services.php (made if missing); an existing entry is refused, never overwritten. @param list<string> $keys */
    private function services(string $slug, array $keys, string $leaf): string
    {
        $file = $this->base.'/config/services.php';
        $current = is_file($file) ? (string) file_get_contents($file) : "<?php\n\nreturn [\n];\n";
        if (preg_match("/^\\s*'".preg_quote($slug, '/')."'\\s*=>/m", $current) === 1) {
            throw new InvalidArgumentException("config/services.php already has a {$slug} entry; standards:make never overwrites.");
        }
        $at = strrpos($current, '];');
        if ($at === false) {
            throw new InvalidArgumentException("config/services.php does not return an array; add the {$slug} keys yourself.");
        }
        $entry = "\n    // standards-pattern: {$leaf}\n    '{$slug}' => [\n".implode('', array_map(fn (string $key): string => "        {$key},\n", $keys))."    ],\n";
        $this->write('config/services.php', rtrim(substr($current, 0, $at))."\n".$entry.substr($current, $at));

        return 'config/services.php';
    }

    /** Adds missing keys to .env.example, never a value: the secret goes in each environment. @param list<string> $lines */
    private function env(array $lines, string $leaf): string
    {
        $file = $this->base.'/.env.example';
        $current = is_file($file) ? (string) file_get_contents($file) : '';
        $missing = array_filter($lines, fn (string $line): bool => preg_match('/^'.preg_quote(strtok($line, '='), '/').'=/m', $current) !== 1);
        if ($missing !== []) {
            $this->write('.env.example', ltrim(rtrim($current)."\n\n# standards-pattern: {$leaf}\n".implode("\n", $missing)."\n", "\n"));
        }

        return '.env.example';
    }

    /**
     * The typed ViewModel base and where ViewModels live, as the app's standards configuration places them (its typed base, its first ViewModel root
     * outside the UI roots and that root's namespace), so generated ViewModels pass rule 20 wherever the app keeps them. standards.make `view_model` and `view_models` override it;
     * without a configuration it is the kit's base in app/ViewModels.
     *
     * @return array{0: string, 1: array{0: string, 1: string}} the base class, and the folder and its namespace
     */
    private function viewModels(string $app, string $namespace): array
    {
        $file = $this->base.'/'.($this->paths['configuration'] ?? 'tests/Arch/configuration.php');
        $config = is_file($file) ? (static fn (string $file): mixed => require $file)($file) : null;
        $config = $config instanceof Configuration ? $config : null;
        $base = ($this->paths['view_model'] ?? null) ?: ((string) $config?->typedViewModel ?: 'Tey\\Kit\\Features\\Core\\ViewModels\\ViewModel');
        // A ViewModel reads the model, which the UI layer may not (rule 22): the first ViewModel root outside the UI roots.
        $roots = array_values(array_filter($config->viewModelRoots ?? [], fn (string $root): bool => array_filter($config->uiRoots ?? [], fn (string $ui): bool => fnmatch($ui, rtrim((string) preg_replace('/\*.*$/', '', $root), '/'))) === []));
        $folder = trim((string) preg_replace('/\*.*$/', '', $this->paths['view_models'] ?? $roots[0] ?? $app.'/ViewModels'), '/');
        $folder = str_contains($folder, '/') ? $folder : $app.'/ViewModels';
        $relative = substr($folder, strlen($app) + 1);

        return [$base, [$folder, $namespace.'\\'.str_replace('/', '\\', $relative)]];
    }

    /**
     * @param  array<string, string>  $values
     * @return array{0: array<string, string|array{0: string, 1: array<string, string>}>, 1: string}
     */
    private function page(string $flavor, string $name, string $slug, string $app, string $pages, string $tests, array &$values): array
    {
        $controller = "{$values['namespace']}\\Http\\Controllers\\{$name}Controller";
        $directory = ucfirst($flavor);
        $files = [
            "{$app}/Http/Controllers/{$name}Controller.php" => "page.{$flavor}.controller.stub",
            "{$pages}/{$name}/{$directory}/Page.vue" => "page.{$flavor}.vue.stub",
            "{$tests}/{$name}/{$directory}PageTest.php" => 'page.test.stub',
        ];

        if ($flavor === 'form') {
            $files["{$app}/Http/Requests/Save{$name}Request.php"] = 'page.form.request.stub';
            $files["{$app}/Actions/Save{$name}.php"] = ['action.stub', ['action' => "Save{$name}"]];
            $values['test_suffix'] = '/create';

            return [$files, self::group($controller, ["Route::get('/{$slug}/create', 'create')->name('{$slug}.create');", "Route::post('/{$slug}', 'store')->name('{$slug}.store');"])];
        }

        if ($flavor === 'show') {
            $values['test_suffix'] = '/1';

            return [$files, self::group($controller, ["Route::get('/{$slug}/{id}', 'show')->name('{$slug}.show');"])];
        }

        return [$files, self::group($controller, ["Route::get('/{$slug}', 'index')->name('{$slug}.index');"])];
    }

    /** @param list<string> $routes */
    private static function group(string $controller, array $routes): string
    {
        return "Route::controller(\\{$controller}::class)->group(function () {\n".implode("\n", array_map(fn (string $route): string => '    '.$route, $routes))."\n});";
    }

    /**
     * Pest applies the app's TestCase only to the folders tests/Pest.php names
     * with ->in(). A generated test outside them binds the TestCase itself, so
     * $this->get() and the container work wherever the test lands.
     */
    private function covered(string $target, string $contents): string
    {
        $pest = is_file($this->base.'/tests/Pest.php') ? (string) file_get_contents($this->base.'/tests/Pest.php') : '';
        $relative = (string) preg_replace('#^tests/#', '', $target);

        if (! str_starts_with($target, 'tests/')) {
            return $contents;
        }

        preg_match_all('/->in\(([^)]*)\)/', $pest, $calls);

        foreach ($calls[1] as $arguments) {
            preg_match_all('/[\'"]([^\'"]+)[\'"]/', $arguments, $globs);

            foreach ($globs[1] as $glob) {
                $glob = trim($glob, '/');

                if ($glob === '.' || $glob === '' || str_starts_with($relative, $glob.'/') || fnmatch($glob, $relative, FNM_PATHNAME) || fnmatch($glob, dirname($relative), FNM_PATHNAME)) {
                    return $contents;
                }
            }
        }

        $testCase = $this->applicationCase($pest);
        $stamp = '// '.Hook::STAMP;
        $at = strpos($contents, $stamp);

        if ($at === false) {
            return $contents;
        }

        $end = strpos($contents, "\n", $at) + 1;
        // With an import block, the test case joins it (sorted, as Pint's ordered_imports wants) and extend() names it short, after the block.
        if (preg_match('/\A((?:use [^;]+;\n)+)/', ltrim(substr($contents, $end), "\n"), $block) === 1) {
            $offset = $end + strpos(substr($contents, $end), $block[1]);
            $imports = array_values(array_unique([...array_filter(explode("\n", $block[1])), 'use '.$testCase.';']));
            usort($imports, fn (string $a, string $b): int => strcasecmp(str_replace('\\', ' ', $a), str_replace('\\', ' ', $b)));

            return substr($contents, 0, $offset).implode("\n", $imports)."\n\npest()->extend(".class_basename($testCase)."::class);\n".substr($contents, $offset + strlen($block[1]));
        }

        return substr($contents, 0, $end)."\npest()->extend(\\".$testCase."::class);\n".substr($contents, $end);
    }

    /**
     * The application's test case from tests/Pest.php: the extend()/uses()
     * whose ->in() covers general tests (a glob, Feature or Unit), never one
     * scoped to browser tests; else Tests\TestCase. Names resolve through the
     * file's use imports.
     */
    private function applicationCase(string $pest): string
    {
        $imports = [];

        preg_match_all('/^use\s+\\\\?([\w\\\\]+)(?:\s+as\s+(\w+))?\s*;/m', $pest, $uses, PREG_SET_ORDER);

        foreach ($uses as $use) {
            $alias = ($use[2] ?? '') !== '' ? $use[2] : substr((string) strrchr('\\'.$use[1], '\\'), 1);
            $imports[$alias] = $use[1];
        }

        $resolve = function (string $name) use ($imports): string {
            $name = ltrim($name, '\\');
            $first = explode('\\', $name)[0];

            return isset($imports[$first]) ? $imports[$first].substr($name, strlen($first)) : $name;
        };

        $candidates = [];

        preg_match_all('/(?:extend|uses)\(\s*([\\\\\w]+)::class[^;]*?->in\(([^)]*)\)/s', $pest, $chains, PREG_SET_ORDER);

        foreach ($chains as $chain) {
            preg_match_all('/[\'"]([^\'"]+)[\'"]/', $chain[2], $folders);
            $folders = $folders[1];

            if ($folders !== [] && array_filter($folders, fn (string $folder): bool => stripos($folder, 'browser') === false) === []) {
                continue;
            }

            if (stripos($chain[1], 'browser') !== false) {
                continue;
            }

            $general = array_filter($folders, fn (string $folder): bool => str_contains($folder, '*') || in_array(trim($folder, '/'), ['.', '', 'Feature', 'Unit'], true));
            $candidates[] = [$general !== [] ? 0 : 1, $resolve($chain[1])];
        }

        usort($candidates, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        if ($candidates !== [] && $candidates[0][0] === 0) {
            return $candidates[0][1];
        }

        if (is_file($this->base.'/tests/TestCase.php')) {
            return 'Tests\\TestCase';
        }

        return $candidates[0][1] ?? 'Tests\\TestCase';
    }

    /**
     * Runs the mod generator, then stamps every PHP file it created. The
     * generator's own output decides the paths, so both runs stay
     * deterministic for the same app layout.
     *
     * @return list<string>
     */
    private function delegate(string $generator, string $name, string $leaf): array
    {
        if (! in_array($generator, $this->generators, true) || $this->run === null) {
            throw new InvalidArgumentException("This unit delegates to tey/mod's {$generator}; install tey/mod (composer require tey/mod) and try again.");
        }

        $before = $this->files();
        $exit = ($this->run)($generator, ['name' => $name, '--no-interaction' => true]);

        if ($exit !== 0) {
            throw new InvalidArgumentException("{$generator} {$name} failed with exit code {$exit}.");
        }

        $created = array_values(array_diff($this->files(), $before));
        sort($created);

        foreach ($created as $file) {
            $contents = (string) file_get_contents($this->base.'/'.$file);

            if (str_ends_with($file, '.php') && str_starts_with($contents, '<?php') && ! str_contains($contents, Hook::STAMP)) {
                file_put_contents($this->base.'/'.$file, (string) preg_replace('/^<\?php\R/', "<?php\n\n// ".Hook::STAMP." {$leaf}\n", $contents, 1));
            }
        }

        if ($created === []) {
            throw new InvalidArgumentException("{$generator} {$name} created no files; nothing to stamp.");
        }

        return $created;
    }

    /** @return list<string> project-relative files under the roots generators write to */
    private function files(): array
    {
        $files = [];

        foreach (['app', 'database', 'tests', 'src', 'modules'] as $root) {
            if (! is_dir($this->base.'/'.$root)) {
                continue;
            }

            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base.'/'.$root, \FilesystemIterator::SKIP_DOTS)) as $file) {
                $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($this->base) + 1));
            }
        }

        return $files;
    }

    private function leaf(string $unit, string $flavor): string
    {
        // The page leaves moved to crud; a single page still stamps the leaf it implements.
        if ($unit === 'page' && $this->catalog->node('ui-page.'.$flavor) instanceof Leaf) {
            return 'ui-page.'.$flavor;
        }

        // Every operator command flavor implements the one operator-command leaf.
        if ($unit === 'command' && $this->catalog->node('background.command') instanceof Leaf) {
            return 'background.command';
        }

        $make = "standards:make {$unit} --flavor={$flavor}";
        $matches = array_values(array_filter($this->catalog->leaves(), fn (Leaf $leaf): bool => $leaf->make === $make));
        usort($matches, fn (Leaf $a, Leaf $b): int => ($a->status === 'ready' ? 0 : 1) <=> ($b->status === 'ready' ? 0 : 1));

        return $matches[0]->id ?? 'gap';
    }

    /** @param array<string, string> $values */
    private function render(string $stub, array $values): string
    {
        $custom = $this->paths['stubs'] ?? null;
        $path = $custom !== null && is_file($this->base.'/'.$custom.'/'.$stub) ? $this->base.'/'.$custom.'/'.$stub : dirname(__DIR__, 2).'/resources/stubs/'.$stub;
        $contents = (string) file_get_contents($path);

        return (string) preg_replace_callback('/\{\{ ([a-z_]+) \}\}/', fn (array $m): string => $values[$m[1]] ?? throw new InvalidArgumentException("Stub {$stub} uses unknown {$m[1]}."), $contents);
    }

    private function write(string $target, string $contents): void
    {
        $file = $this->base.'/'.$target;

        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }

        file_put_contents($file, $contents);
    }
}
