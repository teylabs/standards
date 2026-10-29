<?php

declare(strict_types=1);

namespace Tey\Standards\Adoption;

use Tey\Standards\Ignored;
use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tey\Standards\Checks\KitOwnership;
use Tey\Standards\Configuration;

/**
 * The app as standards:adoption reads it: its frontend (`.vue`, `.ts`, `.js`) and PHP files, its Blade views, composer.json and
 * package.json, and which files tey/kit owns (KitOwnership: the `components/Kit/` copy and unedited lineage-recorded files). Static only;
 * nothing is executed or booted. A file it cannot read fails closed.
 */
final class App
{
    /** Module route folders read even without a configuration naming them: tey/mod's `app/Modules`, and the `app-modules`/`modules` layouts. */
    public const MODULE_ROUTES = ['app/Modules/*/routes', 'app-modules/*/routes', 'modules/*/routes'];

    private const SKIP = ['vendor', 'node_modules', '.git', 'storage', 'bootstrap/cache', 'public/build'];

    /** A path segment that names a public, guest or focus surface (as ui.kit-shell reads it). Everything else is the signed-in app. */
    private const PUBLIC = '~(?:^|/)(?:[Aa]uth|[Ll]ogin|[Gg]uest|[Pp]ublic|[Mm]arketing|[Ll]anding|[Ww]elcome|[Ff]ocus|[Dd]ocs|[Ll]egal|[Hh]elp|[Ee]rror|[Pp]rint)(?![a-z])~';

    /** @var array<string, string> */
    private array $sources = [];

    /** @var list<string>|null */
    private ?array $frontend = null;

    /** @var list<string>|null */
    private ?array $php = null;

    /** @var list<string>|null */
    private ?array $views = null;

    /** @var list<string>|null */
    private ?array $routes = null;

    private readonly KitOwnership $kit;

    /** @var list<string> */
    public readonly array $frontendRoots;

    /** @var list<string> */
    public readonly array $phpRoots;

    /**
     * Route roots: `routes`, the configuration's `routeRoots` (module globs such as `app/Modules/*\/routes`, as rule 55 reads them), and
     * the module layouts an app without a configuration may use (MODULE_ROUTES).
     *
     * @var list<string>
     */
    public readonly array $routeRoots;

    public function __construct(public readonly string $base, ?Configuration $config = null)
    {
        $this->kit = KitOwnership::of($config ?? self::bare($base));
        $this->frontendRoots = array_values(array_unique(array_map(fn (string $root): string => trim($root, '/'), $config === null ? ['resources/js'] : ($config->frontendRoots ?? ['resources/js', ...$config->uiRoots]))));
        $this->phpRoots = array_values(array_unique(array_map(fn (string $root): string => trim($root, '/'), ['app', 'src', 'modules', 'app-modules', 'routes', 'database/migrations', 'config', ...array_keys($config?->classRoots ?? []), ...($config?->sourceRoots ?? [])])));
        $this->routeRoots = array_values(array_unique(array_map(fn (string $root): string => trim($root, '/'), ['routes', ...($config?->routeRoots ?? []), ...self::MODULE_ROUTES])));
    }

    /** A Configuration that names only the base, for KitOwnership when the app has none (a calibration scan). */
    public static function bare(string $base): Configuration
    {
        return new Configuration(base: $base, classRoots: [], sourceRoots: [], uiRoots: [], uiNamespaces: [], hostNamespaces: [], controllerRoots: [], routeRoots: [], controllerSuffix: 'Controller', routeDirectory: 'routes', viewModelRoots: [], viewModelBases: [], typedViewModel: '', viewModelSuffix: 'ViewModel', pageRoots: [], pageScaffolds: [], placements: [], retired: [], discoveryRoots: [], developmentNamespaces: [], safeAttributes: [], mediaTrait: '', mediaInterface: '');
    }

    /** @return list<string> project-relative `.vue`, `.ts` and `.js` files under the frontend roots, sorted */
    public function frontend(): array
    {
        return $this->frontend ??= $this->files($this->frontendRoots, ['vue', 'ts', 'js', 'tsx', 'jsx']);
    }

    /** @return list<string> project-relative `.vue` files */
    public function vue(): array
    {
        return array_values(array_filter($this->frontend(), fn (string $path): bool => str_ends_with($path, '.vue')));
    }

    /** @return list<string> project-relative PHP files (app, src, modules, app-modules, routes, migrations, config and the configured roots), sorted */
    public function php(): array
    {
        return $this->php ??= array_values(array_filter($this->files($this->phpRoots, ['php']), fn (string $path): bool => ! str_ends_with($path, '.blade.php')));
    }

    /** @return list<string> project-relative Blade views */
    public function views(): array
    {
        return $this->views ??= array_values(array_filter($this->files(['resources/views'], ['php']), fn (string $path): bool => str_ends_with($path, '.blade.php')));
    }

    /**
     * The Blade views template-tag `in: ['blade']` reads: every view under `resources/views` except mail and PDF templates (a `mail`,
     * `mails`, `email`, `emails`, `pdf` or `pdfs` folder at any depth), published package views (`resources/views/vendor/`) and error
     * pages (`resources/views/errors/`). Livewire and Volt views (`resources/views/livewire/**`) are ordinary views here. Gitignored
     * files, `public/build` and Storybook stay out as everywhere; kit-owned files are dropped where the scanner reads them (lineage).
     *
     * @return list<string>
     */
    public function bladeFiles(): array
    {
        return array_values(array_filter($this->views(), fn (string $path): bool => preg_match('~^resources/views/(?:vendor|errors)/|^resources/views/(?:.+/)?(?:mail|mails|email|emails|pdf|pdfs)/~', $path) !== 1));
    }

    /** @return list<string> project-relative route files: PHP under `routes/` and every module route root (globs expanded), sorted */
    public function routes(): array
    {
        if ($this->routes !== null) {
            return $this->routes;
        }
        $roots = [];
        foreach ($this->routeRoots as $root) {
            foreach (str_contains($root, '*') ? (glob($this->base.'/'.$root, GLOB_ONLYDIR) ?: []) : [$this->base.'/'.$root] as $directory) {
                $roots[] = str_replace('\\', '/', substr($directory, strlen($this->base) + 1));
            }
        }

        return $this->routes = array_values(array_filter($this->files(array_values(array_unique($roots)), ['php']), fn (string $path): bool => ! str_ends_with($path, '.blade.php')));
    }

    /** @return list<string> */
    public function migrations(): array
    {
        return array_values(array_filter($this->php(), fn (string $path): bool => str_starts_with($path, 'database/migrations/')));
    }

    public function exists(string $path): bool
    {
        return is_file($this->base.'/'.$path) && ! is_link($this->base.'/'.$path);
    }

    /** @throws AdoptionError */
    public function source(string $path): string
    {
        if (isset($this->sources[$path])) {
            return $this->sources[$path];
        }
        $file = $this->base.'/'.$path;
        $source = is_readable($file) ? @file_get_contents($file) : false;
        if ($source === false) {
            throw new AdoptionError("Cannot read {$path}; standards:adoption fails closed until it can.");
        }

        return $this->sources[$path] = $source;
    }

    /** Whether tey/kit owns the file (the components/Kit copy, or an unedited lineage-recorded file): it never counts as the app's own. */
    public function kitOwned(string $path): bool
    {
        return $this->kit->owns($path, $this->source($path));
    }

    /** Under a `pages/` folder of a frontend root. */
    public function page(string $path): bool
    {
        foreach ($this->frontendRoots as $root) {
            if (preg_match('~^'.preg_quote($root, '~').'/(?:.+/)?[Pp]ages/~', $path) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Storybook, never the app's UI: a `*.stories.*` file, anything under `.storybook/`, and a lowercase `stories/` folder outside a
     * `pages/` folder (a component library's story set). Excluded from every signal, fact and gap scan.
     */
    public static function storybook(string $path): bool
    {
        return str_contains(basename($path), '.stories.')
            || preg_match('~(?:^|/)\.storybook/~', $path) === 1
            || (preg_match('~(?:^|/)stories/~', $path) === 1 && preg_match('~(?:^|/)[Pp]ages/(?:.+/)?stories/~', $path) !== 1);
    }

    /** `public` when a path segment names a public, guest or focus surface, else `signed-in`. */
    public static function scope(string $path): string
    {
        return preg_match(self::PUBLIC, $path) === 1 ? 'public' : 'signed-in';
    }

    /** @return array<string, mixed>|null */
    public function json(string $path): ?array
    {
        if (! $this->exists($path)) {
            return null;
        }
        $data = json_decode($this->source($path), true);
        if (! is_array($data)) {
            throw new AdoptionError("{$path} is not valid JSON; standards:adoption fails closed until it is.");
        }

        return $data;
    }

    /** @return array<string, string> composer require and require-dev */
    public function composerPackages(): array
    {
        $composer = $this->json('composer.json') ?? [];

        return [...(is_array($composer['require-dev'] ?? null) ? $composer['require-dev'] : []), ...(is_array($composer['require'] ?? null) ? $composer['require'] : [])];
    }

    /** @return array<string, string> package.json dependencies and devDependencies */
    public function npmPackages(): array
    {
        $package = $this->json('package.json') ?? [];

        return [...(is_array($package['devDependencies'] ?? null) ? $package['devDependencies'] : []), ...(is_array($package['dependencies'] ?? null) ? $package['dependencies'] : [])];
    }

    public function npmName(): ?string
    {
        $name = ($this->json('package.json') ?? [])['name'] ?? null;

        return is_string($name) ? $name : null;
    }

    public static function line(string $source, int $offset): int
    {
        return substr_count($source, "\n", 0, max(0, min($offset, strlen($source)))) + 1;
    }

    /**
     * `**` crosses directories, `*` stays within one segment, `?` is one character. Case-sensitive: a component or class name glob
     * (`*Button`) means that case.
     */
    public static function glob(string $glob, string $value, bool $caseless = false): bool
    {
        $pattern = preg_quote($glob, '#');
        $pattern = str_replace(['/\*\*/', '\*\*', '\*', '\?'], ['/(?:.*/)?', '.*', '[^/]*', '[^/]'], $pattern);

        return preg_match('#\A'.$pattern.'\z#'.($caseless ? 'i' : ''), $value) === 1;
    }

    /**
     * A path glob (one with a `/`) against a project-relative path, case-insensitively: `resources/js/pages/Auth/**` also matches the
     * Jetstream/Breeze layout's `resources/js/Pages/Auth/Login.vue`, and any other segment (`settings/` or `Settings/`) likewise. The
     * match is string-based, so it is the same on a case-sensitive file system.
     */
    public static function pathGlob(string $glob, string $path): bool
    {
        return self::glob($glob, $path, true);
    }

    /**
     * @param  list<string>  $roots
     * @param  list<string>  $extensions
     * @return list<string>
     */
    private function files(array $roots, array $extensions): array
    {
        $found = [];
        foreach ($roots as $root) {
            $directory = $this->base.'/'.$root;
            if ($root === '' || ! is_dir($directory) || is_link($directory)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
                fn (SplFileInfo $file): bool => ! $file->isLink() && ! in_array(str_replace('\\', '/', substr($file->getPathname(), strlen($this->base) + 1)), self::SKIP, true) && $file->getFilename() !== 'node_modules',
            ));
            foreach ($iterator as $file) {
                /** @var SplFileInfo $file */
                if ($file->isFile() && in_array(strtolower($file->getExtension()), $extensions, true)) {
                    // Project-relative with forward slashes on every OS.
                    $found[] = str_replace('\\', '/', substr($file->getPathname(), strlen($this->base) + 1));
                }
            }
        }
        $found = Ignored::filter($this->base, array_values(array_filter(array_unique($found), fn (string $path): bool => ! self::storybook($path))));
        sort($found);

        return $found;
    }
}
