<?php

declare(strict_types=1);

namespace Tey\Standards\Enforcement;

use InvalidArgumentException;
use Tey\Standards\Patterns\Compiler;
use Tey\Standards\Patterns\Leaf;
use Tey\Standards\Patterns\PatternCatalog;

/**
 * Emits conformant code for a pattern leaf from recipe stubs. An app or kit
 * can point standards.make.stubs at its own copies. Every file carries the
 * leaf's pattern stamp; existing files are never overwritten.
 */
final class Maker
{
    /**
     * @param  array{namespace?: string, app?: string, pages?: string, routes?: string, tests?: string, stubs?: ?string}  $paths
     */
    public function __construct(private readonly string $base, private readonly PatternCatalog $catalog, private readonly array $paths = []) {}

    /** @return list<string> the project-relative files written or changed */
    public function make(string $unit, string $name, string $flavor): array
    {
        if (! in_array($flavor, Compiler::UNITS[$unit] ?? [], true)) {
            throw new InvalidArgumentException("Unknown unit or flavor {$unit} --flavor={$flavor}. Units: ".implode('; ', array_map(fn (string $u, array $f): string => $u.' ('.implode('|', $f).')', array_keys(Compiler::UNITS), Compiler::UNITS)).'.');
        }

        if (preg_match('/^[A-Z][A-Za-z0-9]*$/D', $name) !== 1) {
            throw new InvalidArgumentException('Name must be StudlyCase, for example Invoices.');
        }

        $leaf = $this->leaf($unit, $flavor);
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
            'ability' => str_replace('-', '_', $slug).'.'.($flavor === 'write' ? 'update' : 'read'),
            'writes' => $flavor === 'write' ? "\n    public const WRITES = true;\n" : '',
            'rules' => $flavor === 'toggles' ? "'updates' => ['sometimes', 'boolean']" : "'name' => ['required', 'string', 'max:255']",
        ];

        [$files, $route] = match ($unit) {
            'page' => $this->page($flavor, $name, $slug, $app, $pages, $tests, $values),
            'action' => [[
                "{$app}/Actions/{$name}.php" => 'action.stub',
                "{$tests}/Actions/{$name}Test.php" => 'action.test.stub',
            ], null],
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

        foreach ($files as $target => $stub) {
            if (is_file($this->base.'/'.$target)) {
                throw new InvalidArgumentException("{$target} already exists; standards:make never overwrites.");
            }
        }

        $written = [];

        foreach ($files as $target => $stub) {
            [$stub, $overrides] = is_array($stub) ? $stub : [$stub, []];
            $this->write($target, $this->render($stub, [...$values, ...$overrides]));
            $written[] = $target;
        }

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

    private function leaf(string $unit, string $flavor): string
    {
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
