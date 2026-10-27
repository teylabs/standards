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
    ) {}

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

        if (array_diff($options, $unit === 'crud' ? self::CRUD_OPTIONS : []) !== []) {
            throw new InvalidArgumentException('Unknown option --'.implode(', --', array_diff($options, $unit === 'crud' ? self::CRUD_OPTIONS : [])).' for '.$unit.'.');
        }

        if (isset(self::DELEGATED[$unit])) {
            return $this->delegate(self::DELEGATED[$unit], $name, $leaf);
        }

        if ($unit === 'crud') {
            return $this->crud($name, $options);
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
            $contents = $this->render($stub, [...$values, ...$overrides]);
            $this->write($target, str_ends_with($target, 'Test.php') ? $this->covered($target, $contents) : $contents);
            $written[] = $target;
        }

        return $written;
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
        $migration = date('Y_m_d_His').'_create_'.$table.'_table.php';
        $bulk = in_array('bulk', $options, true);
        $soft = in_array('soft-deletes', $options, true);
        $values = [
            'namespace' => $namespace, 'name' => $name, 'plural' => $plural, 'slug' => $slug, 'table' => $table, 'perm' => $table,
            'var' => Str::camel($name), 'title' => $title, 'title_lower' => strtolower($title), 'titles' => $titles, 'titles_lower' => strtolower($titles),
            'view_model' => ltrim($viewModel, '\\'), 'view_model_base' => class_basename($viewModel), 'vm_namespace' => $viewModels[1].'\\'.$plural, 'migration' => $migration, 'leaf' => 'ui-page.crud-resource',
            'soft_import' => $soft ? "\nuse Illuminate\\Database\\Eloquent\\SoftDeletes;" : '', 'soft_use' => $soft ? "\n    use SoftDeletes;\n" : '',
            'soft_column' => $soft ? "\n            \$table->softDeletes();" : '',
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
            "{$app}/Models/{$name}.php" => 'crud/model.stub',
            "database/migrations/{$migration}" => 'crud/migration.stub',
            "{$app}/Policies/{$name}Policy.php" => 'crud/policy.stub',
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

        $providers = 'bootstrap/providers.php';
        $file = $this->base.'/'.$providers;
        $line = "    // standards-pattern: ui-page.crud-resource\n    \\{$namespace}\\Providers\\{$name}ServiceProvider::class,\n";
        $current = is_file($file) ? (string) file_get_contents($file) : "<?php\n\nreturn [\n];\n";
        $at = strrpos($current, '];');
        if ($at === false) {
            throw new InvalidArgumentException("{$providers} does not return a list; add \\{$namespace}\\Providers\\{$name}ServiceProvider::class to it yourself.");
        }
        $this->write($providers, substr($current, 0, $at).$line.substr($current, $at));
        $written[] = $providers;

        return $written;
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
        $base = $this->paths['view_model'] ?? $config?->typedViewModel ?? 'Tey\\Kit\\Features\\Core\\ViewModels\\ViewModel';
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
