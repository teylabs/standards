<?php

declare(strict_types=1);

namespace Tey\Standards\Enforcement;

use InvalidArgumentException;
use Throwable;

/**
 * The shape of the host a generator writes into, read once: where its files go (tey/mod's layout, when it places by a dimension such as a module),
 * how its frontend imports the kit's components (`@kit/ui` only where the host aliases it), and which tey/kit features it has adopted. A host
 * without tey/mod, or on a layout with no dimension (`laravel`), keeps the generators' conventional paths exactly.
 */
final class HostShape
{
    /** The kit's component barrel, where a host that does not alias `@kit/ui` resolves it. */
    public const KIT_INDEX = '@/components/Kit';

    /** A conventional app-relative folder => the tey/mod file type that places it. */
    private const TYPES = [
        'Http/Controllers' => 'controller',
        'Http/Requests' => 'request',
        'Policies' => 'policy',
        'Providers' => 'provider',
        'Models' => 'model',
        'Actions' => 'action',
        'Queries' => 'query',
        'Enums' => 'enum',
        'Console/Commands' => 'command',
    ];

    /**
     * @param  object|null  $layout  tey/mod's CompiledLayout (Mod::current()); null without tey/mod
     * @param  list<string>|null  $features  the adopted tey/kit features; null when the host cannot say (every kit class is then taken as present)
     */
    public function __construct(
        public readonly ?object $layout = null,
        public readonly string $kitUi = '@kit/ui',
        public readonly ?array $features = null,
        public readonly ?string $module = null,
        private readonly string $app = 'app',
        private readonly string $namespace = 'App',
    ) {}

    /** The host as it is: tey/mod's active layout when the container has one, its `@kit/ui` alias and the kit features it adopted. */
    public static function detect(string $base, ?string $module = null): self
    {
        $layout = null;
        try {
            if (class_exists('Tey\\Mod\\Facades\\Mod') && function_exists('app') && app()->bound('Tey\\Mod\\Layout\\CompiledLayout')) {
                $layout = \Tey\Mod\Facades\Mod::current();
            }
        } catch (Throwable) {
            $layout = null;
        }

        return new self($layout, self::kitUiAlias($base), self::adoptedFeatures($base), $module);
    }

    /** `@kit/ui` when the host's tsconfig or Vite config aliases it; otherwise the kit's own barrel, `@/components/Kit`. */
    public static function kitUiAlias(string $base): string
    {
        foreach (['tsconfig.json', 'tsconfig.app.json', 'jsconfig.json', 'vite.config.ts', 'vite.config.js', 'vite.config.mts', 'vite.config.mjs'] as $file) {
            $path = $base.'/'.$file;
            if (! is_link($path) && is_file($path) && preg_match('/[\'"]@kit\/ui[\'"]\s*:/', (string) file_get_contents($path)) === 1) {
                return '@kit/ui';
            }
        }

        return self::KIT_INDEX;
    }

    /**
     * The tey/kit features the host adopted: `kit.features` when it is a list, else the features of its installed lineages; null when tey/kit is absent
     * or the host cannot be read (then nothing is refused on this ground).
     *
     * @return list<string>|null
     */
    public static function adoptedFeatures(string $base): ?array
    {
        if (! class_exists('Tey\\Kit\\Features\\FeaturesServiceProvider')) {
            return null;
        }
        try {
            $configured = function_exists('config') ? config('kit.features') : null;
            if (is_array($configured)) {
                return array_values(array_filter($configured, 'is_string'));
            }
            $appId = function_exists('config') ? (config('kit.app_id') ?? config('kit-lifecycle.app_id')) : null;
            if (! is_string($appId) || $appId === '') {
                return null;
            }
            $adopted = \Tey\Kit\Features\FeaturesServiceProvider::adopted($base, $appId, null);

            return $adopted === null ? null : array_values($adopted);
        } catch (Throwable) {
            return null;
        }
    }

    /** The kit components the host's own copy of the kit barrel exports, or null when it has none (then nothing is refused on this ground). @return list<string>|null */
    public static function kitComponents(string $base): ?array
    {
        $barrel = $base.'/resources/js/components/Kit/index.ts';
        if (is_link($barrel) || ! is_file($barrel) || ! class_exists('Tey\\Standards\\Patterns\\KitExports')) {
            return null;
        }

        return \Tey\Standards\Patterns\KitExports::parse((string) file_get_contents($barrel));
    }

    /** Whether the layout places files by a dimension (a module), so generated files go to the module's tree. */
    public function modular(): bool
    {
        return $this->layout !== null && method_exists($this->layout, 'dimensionNames') && $this->layout->dimensionNames() !== [];
    }

    /** The adopted features miss these; an empty list when they have them all or the host cannot say. @param list<string> $needed @return list<string> */
    public function missingFeatures(array $needed): array
    {
        return $this->features === null ? [] : array_values(array_diff($needed, $this->features));
    }

    /** Refuses a modular host's generation without `--module`, naming the layout's dimension. */
    public function requireModule(): void
    {
        if ($this->modular() && ($this->module === null || $this->module === '')) {
            $dimension = $this->layout->dimensionNames()[0];
            throw new InvalidArgumentException("This app's tey/mod layout places files by {$dimension}: pass --module=<Name> (for example --module=Billing).");
        }
    }

    /**
     * Where a conventionally placed file goes in this host, as `[path, page render name or null]`. Unchanged unless the layout is modular: then a class
     * goes where tey/mod places its type in the module (a folder tey/mod has no type for stays under the module's root), a test to the layout's test
     * folder, a page to the module's pages (rendered `<Module>::<name>`), and a route file or migration under the module.
     *
     * @return array{0: string, 1: ?string}
     */
    public function place(string $path): array
    {
        if (! $this->modular()) {
            return [$path, null];
        }
        $this->requireModule();
        $file = basename($path);
        $class = (string) preg_replace('/\.php$/', '', $file);

        if (preg_match('#^resources/js/pages/(.+)\.vue$#', $path, $page) === 1) {
            // The module already scopes its pages: a first folder that repeats the module's name (Slides/Workbench/Page in Slides) is dropped.
            $name = str_starts_with($page[1], $this->module.'/') ? substr($page[1], strlen((string) $this->module) + 1) : $page[1];
            $placed = $this->layout->hasFileType('page') ? $this->artifact('page', $name)->path() : $this->root().'/resources/js/pages/'.$name.'.vue';

            return [$placed, $this->module.'::'.$name];
        }
        if (preg_match('#^tests/(?:Feature/)?(?:(.+)/)?[^/]+$#', $path, $test) === 1) {
            if (! $this->layout->hasFileType('test')) {
                return [$path, null];
            }
            // The conventional subfolder (Posts/IndexPageTest) stays beneath the layout's test folder, so two pages' tests never collide.
            $placed = $this->artifact('test', $class)->path();
            $folder = isset($test[1]) && $test[1] !== $this->module ? '/'.$test[1] : '';

            return [dirname($placed).$folder.'/'.$file, null];
        }
        if (preg_match('#^routes/(.+)$#', $path, $route) === 1) {
            return [$this->root().'/routes/'.$route[1], null];
        }
        if (preg_match('#^database/migrations/(.+)$#', $path, $migration) === 1) {
            return [$this->root().'/Database/Migrations/'.$migration[1], null];
        }
        if (preg_match('#^database/factories/(.+)\.php$#', $path) === 1) {
            return [$this->layout->hasFileType('factory') ? $this->artifact('factory', $class)->path() : $this->root().'/Database/Factories/'.$file, null];
        }
        $prefix = $this->app.'/';
        if (! str_starts_with($path, $prefix)) {
            return [$path, null];
        }
        $folder = dirname(substr($path, strlen($prefix)));
        foreach (self::TYPES as $conventional => $type) {
            if ($folder === $conventional && $this->layout->hasFileType($type)) {
                return [$this->artifact($type, $class)->path(), null];
            }
        }
        // A view model (ViewModels/<Plural>, or a *ViewModel wherever the app's view-model root is): the layout's view-model folder for the module, which
        // already scopes it (no plural folder beneath).
        if ((preg_match('#^ViewModels(?:/.+)?$#', $folder) === 1 || str_ends_with($class, 'ViewModel')) && $this->layout->hasFileType('view-model')) {
            return [$this->artifact('view-model', $class)->path(), null];
        }

        return [$this->root().($folder === '.' ? '' : '/'.$folder).'/'.$file, null];
    }

    /** The PHP namespace of a project-relative PHP file in this host (from tey/mod's roots when modular), or null when it is not a class file. */
    public function namespaceOf(string $path): ?string
    {
        if (! str_ends_with($path, '.php')) {
            return null;
        }
        $directory = dirname($path);
        if ($this->modular()) {
            try {
                $namespace = rtrim((string) $this->layout->namespaceFor($directory), '\\');

                return $namespace === '' ? null : $namespace;
            } catch (Throwable) {
                return null;
            }
        }
        $map = [$this->app => $this->namespace, 'tests' => 'Tests', 'database/factories' => 'Database\\Factories'];
        foreach ($map as $root => $namespace) {
            if ($directory === $root || str_starts_with($directory, $root.'/')) {
                $rest = substr($directory, strlen($root));

                return $namespace.str_replace('/', '\\', $rest);
            }
        }

        return null;
    }

    /** The module's root folder (where a file tey/mod has no type for goes). */
    private function root(): string
    {
        return dirname($this->artifact('class', 'StandardsPlacementProbe')->path());
    }

    private function artifact(string $type, string $name): object
    {
        $dimension = $this->layout->dimensionNames()[0];
        try {
            return $this->layout->place($type, $name, \Tey\Mod\Placement\PlacementContext::of([$dimension => (string) $this->module]));
        } catch (Throwable $error) {
            throw new InvalidArgumentException("tey/mod cannot place {$type} {$name} in {$dimension} {$this->module}: ".$error->getMessage());
        }
    }
}
