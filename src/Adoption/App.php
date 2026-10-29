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

    private readonly KitOwnership $kit;

    /** @var list<string> */
    public readonly array $frontendRoots;

    /** @var list<string> */
    public readonly array $phpRoots;

    public function __construct(public readonly string $base, ?Configuration $config = null)
    {
        $this->kit = KitOwnership::of($config ?? self::bare($base));
        $this->frontendRoots = array_values(array_unique(array_map(fn (string $root): string => trim($root, '/'), $config === null ? ['resources/js'] : ($config->frontendRoots ?? ['resources/js', ...$config->uiRoots]))));
        $this->phpRoots = array_values(array_unique(array_map(fn (string $root): string => trim($root, '/'), ['app', 'src', 'modules', 'routes', 'database/migrations', 'config', ...array_keys($config?->classRoots ?? []), ...($config?->sourceRoots ?? [])])));
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

    /** @return list<string> project-relative PHP files (app, src, modules, routes, migrations, config and the configured roots), sorted */
    public function php(): array
    {
        return $this->php ??= array_values(array_filter($this->files($this->phpRoots, ['php']), fn (string $path): bool => ! str_ends_with($path, '.blade.php')));
    }

    /** @return list<string> project-relative Blade views */
    public function views(): array
    {
        return $this->views ??= array_values(array_filter($this->files(['resources/views'], ['php']), fn (string $path): bool => str_ends_with($path, '.blade.php')));
    }

    /** @return list<string> */
    public function routes(): array
    {
        return array_values(array_filter($this->php(), fn (string $path): bool => str_starts_with($path, 'routes/')));
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

    /** `**` crosses directories, `*` stays within one segment, `?` is one character. */
    public static function glob(string $glob, string $value): bool
    {
        $pattern = preg_quote($glob, '#');
        $pattern = str_replace(['/\*\*/', '\*\*', '\*', '\?'], ['/(?:.*/)?', '.*', '[^/]*', '[^/]'], $pattern);

        return preg_match('#\A'.$pattern.'\z#', $value) === 1;
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
        $found = Ignored::filter($this->base, array_values(array_unique($found)));
        sort($found);

        return $found;
    }
}
