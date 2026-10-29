<?php

declare(strict_types=1);

namespace Tey\Standards;

use DateTimeImmutable;
use InvalidArgumentException;
use PhpParser\Error as ParserError;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpToken;
use Symfony\Component\Finder\Finder;

/**
 * Finds the places a project breaks the CRUDDY controller rule.
 *
 *  - A controller's public surface is the seven resource actions, or one
 *    `__invoke`. `__construct` is always allowed; `middleware` only as the
 *    static `HasMiddleware` hook.
 *  - Routes are `Route::controller(X::class)->...->group()` groups. Flat
 *    `[X::class, 'method']` tuples, `'X@method'` strings, routed custom methods
 *    and `Route::resource()` / `singleton()` / `apiResource()` are violations.
 *
 * Exceptions are narrow: one entry per violation (kind + file + subject), each
 * with a reason and an expiry date. An exempted file still fails for any NEW
 * violation in it, and an exception that no longer matches a violation is
 * itself an error, so the list can only shrink.
 *
 * The URI-verb check is a heuristic. It cannot prove a route models a resource;
 * the reviewer still checks that the noun makes sense and is authorised.
 *
 * Roots are glob patterns relative to the project. A scan that finds no
 * controller and no route file is reported as not verified, never as a pass.
 *
 * See docs/recipes/backend/cruddy-controllers.md.
 */
final class CruddyGuard
{
    public const ACTIONS = ['index', 'create', 'store', 'show', 'edit', 'update', 'destroy'];

    private const VERBS = 'toggle|mark|approve|reorder|duplicate|copy|publish|restore|do';

    /**
     * @param  array<int, string>  $controllerRoots  e.g. 'app/Http/Controllers', 'app/Modules/*\/Controllers'
     * @param  array<int, string>  $routeRoots  e.g. 'routes', 'app/Modules/*\/routes'
     * @param  array<int, array{kind: string, file: string, subject: string, reason: string, expires: string}>  $exceptions
     * @param  array<int, string>  $searchRoots  where to look for controllers and route files that no root maps
     * @param  array<int, string>  $nativeNamespaces  namespaces a native `arch()` rule already checks (pass the SAME list to `arch()`). The `method` check is skipped for their files, so each is verified: it must resolve through composer's PSR-4 map to a real directory holding controllers, and every controller there must declare a namespace inside it. Otherwise the run fails rather than leave a hole.
     */
    public function __construct(
        private readonly string $base,
        private readonly array $controllerRoots,
        private readonly array $routeRoots,
        private readonly array $exceptions = [],
        private readonly array $searchRoots = [],
        private readonly ?DateTimeImmutable $today = null,
        private readonly array $nativeNamespaces = [],
        private readonly string $controllerSuffix = 'Controller',
        private readonly string $routeDirectory = 'routes',
    ) {}

    /**
     * @return array{
     *     controllers: int,
     *     routeFiles: int,
     *     verified: bool,
     *     violations: array<int, array{key: string, kind: string, file: string, subject: string}>,
     *     failures: array<int, string>
     * }
     */
    public function run(): array
    {
        $controllers = $this->files($this->controllerRoots, fn (string $file): bool => str_ends_with($file, $this->controllerSuffix.'.php'));
        $routeFiles = $this->files($this->routeRoots, fn (string $file): bool => str_ends_with($file, '.php'));

        $nativeDirectories = $this->nativeDirectories();
        $violations = $this->nativeCoverage($controllers, $nativeDirectories);

        foreach ($controllers as $file) {
            foreach ($this->controllerViolations($file, $nativeDirectories) as $violation) {
                $violations[] = $violation;
            }
        }

        foreach ($routeFiles as $file) {
            foreach ($this->routeViolations($file) as $violation) {
                $violations[] = $violation;
            }
        }

        foreach ($this->unmapped(array_keys($controllers), array_keys($routeFiles)) as $violation) {
            $violations[] = $violation;
        }

        $violations = array_map(fn (array $violation): array => $violation + ['key' => $violation['kind'].' | '.$violation['file'].' | '.$violation['subject']], $violations);

        return [
            'controllers' => count($controllers),
            'routeFiles' => count($routeFiles),
            'verified' => $controllers !== [] || $routeFiles !== [],
            'violations' => $violations,
            'failures' => $this->failures($violations),
        ];
    }

    /**
     * Violations nobody has excepted, expired exceptions, and exceptions that no
     * longer match anything.
     *
     * @param  array<int, array{key: string, kind: string, file: string, subject: string}>  $violations
     * @return array<int, string>
     */
    private function failures(array $violations): array
    {
        $today = ($this->today ?? new DateTimeImmutable('today'))->setTime(0, 0);
        $byKey = [];

        foreach ($this->exceptions as $exception) {
            foreach (['kind', 'file', 'subject', 'reason', 'expires'] as $field) {
                if (! self::validExceptionField($exception, $field)) {
                    throw new InvalidArgumentException("A CRUDDY exception needs a [{$field}]: exceptions are narrow, reasoned and dated.");
                }
            }

            if (DateTimeImmutable::createFromFormat('!Y-m-d', $exception['expires']) === false) {
                throw new InvalidArgumentException("CRUDDY exception for {$exception['file']} must expire on a Y-m-d date.");
            }

            $byKey[$exception['kind'].' | '.$exception['file'].' | '.$exception['subject']] = $exception;
        }

        $failures = [];
        $violating = array_column($violations, 'key');

        foreach ($violations as $violation) {
            $exception = $byKey[$violation['key']] ?? null;

            if ($exception === null) {
                $failures[] = "violation: {$violation['key']}";
            } elseif (DateTimeImmutable::createFromFormat('!Y-m-d', $exception['expires']) < $today) {
                $failures[] = "expired exception ({$exception['expires']}): {$violation['key']}";
            }
        }

        foreach (array_keys($byKey) as $key) {
            if (! in_array($key, $violating, true)) {
                $failures[] = "stale exception, delete it: {$key}";
            }
        }

        return $failures;
    }

    /** @param array<string, mixed> $exception */
    private static function validExceptionField(array $exception, string $field): bool
    {
        $value = $exception[$field] ?? null;

        return ! empty($value) && is_string($value);
    }

    /**
     * Files under the glob roots, as [relative path => absolute path].
     *
     * @param  array<int, string>  $roots
     * @param  callable(string): bool  $accept
     * @return array<string, string>
     */
    private function files(array $roots, callable $accept): array
    {
        $found = [];

        foreach ($roots as $root) {
            foreach (glob($this->base.'/'.$root, GLOB_ONLYDIR) ?: [] as $directory) {
                foreach ((new Finder)->files()->in($directory)->name('*.php') as $file) {
                    $relative = $this->relative($file->getRealPath());

                    if ($accept($relative)) {
                        $found[$relative] = $file->getRealPath();
                    }
                }
            }
        }

        $found = Ignored::filterKeys($this->base, $found);
        ksort($found);

        return $found;
    }

    /**
     * Controllers and route files that exist but sit outside every mapped root.
     * Without this a project can move its controllers and keep a green, empty scan.
     *
     * @param  array<int, string>  $controllers
     * @param  array<int, string>  $routeFiles
     * @return array<int, array{kind: string, file: string, subject: string}>
     */
    private function unmapped(array $controllers, array $routeFiles): array
    {
        $violations = [];

        foreach ($this->searchRoots as $root) {
            if (! is_dir($this->base.'/'.$root)) {
                continue;
            }

            $relatives = [];
            foreach ((new Finder)->files()->in($this->base.'/'.$root)->name('*.php')->notPath('vendor') as $file) {
                $relatives[] = $this->relative($file->getRealPath());
            }

            foreach (Ignored::filter($this->base, $relatives) as $relative) {
                $isController = str_ends_with($relative, $this->controllerSuffix.'.php');
                $isRouteFile = (bool) preg_match('#(^|/)'.preg_quote($this->routeDirectory, '#').'/[^/]+\.php$#', $relative);

                if ($isController && ! in_array($relative, $controllers, true)) {
                    $violations[] = ['kind' => 'unmapped-controller', 'file' => $relative, 'subject' => 'outside every controller root'];
                }

                if ($isRouteFile && ! in_array($relative, $routeFiles, true)) {
                    $violations[] = ['kind' => 'unmapped-routes', 'file' => $relative, 'subject' => 'outside every route root'];
                }
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, string>  $nativeDirectories  namespace => relative directory
     * @return array<int, array{kind: string, file: string, subject: string}>
     */
    private function controllerViolations(string $path, array $nativeDirectories): array
    {
        $file = $this->relative($path);
        $violations = [];
        $nativelyChecked = $this->inNativeDirectory($file, $nativeDirectories);

        foreach ($this->classes((string) file_get_contents($path)) as $class => $methods) {
            $surface = array_filter($methods, fn (array $method): bool => $method['name'] !== '__construct' && ! ($method['name'] === 'middleware' && $method['static']));
            $names = array_column($surface, 'name');

            foreach ($names as $name) {
                if (! $nativelyChecked && $name !== '__invoke' && ! in_array($name, self::ACTIONS, true) && ! $this->overridesVendor($path, $class, $name)) {
                    $violations[] = ['kind' => 'method', 'file' => $file, 'subject' => "{$class}::{$name}"];
                }
            }

            if (in_array('__invoke', $names, true) && array_intersect($names, self::ACTIONS) !== []) {
                $violations[] = ['kind' => 'mixed-invoke', 'file' => $file, 'subject' => $class];
            }
        }

        return $violations;
    }

    /**
     * Native namespace => relative directory, from composer's PSR-4 map. A namespace
     * that does not resolve to an existing directory throws: skipping the custom
     * check for a directory the native rule cannot see would be an unchecked hole.
     *
     * @return array<string, string>
     */
    private function nativeDirectories(): array
    {
        if ($this->nativeNamespaces === []) {
            return [];
        }

        $composer = json_decode((string) @file_get_contents($this->base.'/composer.json'), true);
        $psr4 = array_merge($composer['autoload']['psr-4'] ?? [], $composer['autoload-dev']['psr-4'] ?? []);
        $directories = [];

        foreach ($this->nativeNamespaces as $namespace) {
            $namespace = trim($namespace, '\\');
            $best = null;

            foreach ($psr4 as $prefix => $path) {
                $prefix = trim($prefix, '\\');

                if (($namespace === $prefix || str_starts_with($namespace, $prefix.'\\')) && ($best === null || strlen($prefix) > strlen($best[0]))) {
                    $best = [$prefix, rtrim((string) (is_array($path) ? $path[0] : $path), '/')];
                }
            }

            $directory = $best === null ? null : trim($best[1].'/'.ltrim(str_replace('\\', '/', substr($namespace, strlen($best[0]))), '/'), '/');

            if ($directory === null || ! is_dir($this->base.'/'.$directory)) {
                throw new InvalidArgumentException("Native CRUDDY namespace [{$namespace}] does not resolve through composer's PSR-4 map to an existing directory, so the native arch() rule cannot be trusted to see it.");
            }

            $directories[$namespace] = $directory;
        }

        return $directories;
    }

    /**
     * @param  array<string, string>  $nativeDirectories
     */
    private function inNativeDirectory(string $file, array $nativeDirectories): bool
    {
        foreach ($nativeDirectories as $directory) {
            if (str_starts_with($file, $directory.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fail closed on the native side: each native namespace holds controllers, and
     * every controller in its directory declares a namespace inside it (a file whose
     * namespace differs is invisible to `arch()` yet would skip the custom check).
     *
     * @param  array<string, string>  $controllers
     * @param  array<string, string>  $nativeDirectories
     * @return array<int, array{kind: string, file: string, subject: string}>
     */
    private function nativeCoverage(array $controllers, array $nativeDirectories): array
    {
        $violations = [];

        foreach ($nativeDirectories as $namespace => $directory) {
            $inside = array_filter(array_keys($controllers), fn (string $file): bool => str_starts_with($file, $directory.'/'));

            if ($inside === []) {
                $violations[] = ['kind' => 'native-empty', 'file' => $directory, 'subject' => "{$namespace} holds no controller the guard maps: the native rule would check nothing"];
            }

            foreach ($inside as $file) {
                preg_match('/^namespace\s+([^;]+);/m', (string) file_get_contents($controllers[$file]), $declared);
                $declared = trim($declared[1] ?? '');

                if ($declared !== $namespace && ! str_starts_with($declared, $namespace.'\\')) {
                    $violations[] = ['kind' => 'native-mismatch', 'file' => $file, 'subject' => "declares [{$declared}], outside native namespace [{$namespace}]"];
                }
            }
        }

        return $violations;
    }

    /**
     * Public methods each class in the source declares itself (inherited ones are
     * not the controller's surface), read from tokens so nothing is autoloaded.
     *
     * @return array<string, array<int, array{name: string, static: bool}>>
     */
    private function classes(string $source): array
    {
        $tokens = PhpToken::tokenize($source);
        $classes = [];
        $current = null;
        $depth = 0;
        $classDepth = null;
        $modifiers = [];

        foreach ($tokens as $index => $token) {
            if ($token->is(T_CLASS) && $current === null) {
                $previous = $this->previousMeaningful($tokens, $index);

                if ($previous === null || ! $previous->is([T_DOUBLE_COLON, T_NEW])) {
                    $name = $this->nextMeaningful($tokens, $index);

                    if ($name !== null && $name->is(T_STRING)) {
                        $current = $name->text;
                        $classes[$current] = [];
                        $classDepth = null;
                    }
                }

                continue;
            }

            if ($token->is(['{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;

                if ($current !== null && $classDepth === null) {
                    $classDepth = $depth;
                }

                $modifiers = [];

                continue;
            }

            if ($token->is('}')) {
                if ($current !== null && $classDepth === $depth) {
                    $current = null;
                    $classDepth = null;
                }

                $depth--;
                $modifiers = [];

                continue;
            }

            if ($current === null || $classDepth === null || $depth !== $classDepth) {
                continue;
            }

            if ($token->is([T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_FINAL, T_ABSTRACT])) {
                $modifiers[] = $token->id;
            } elseif ($token->is(';')) {
                $modifiers = [];
            } elseif ($token->is(T_FUNCTION)) {
                $name = $this->nextMeaningful($tokens, $index);

                if ($name !== null && $name->is(T_STRING) && ! in_array(T_PRIVATE, $modifiers, true) && ! in_array(T_PROTECTED, $modifiers, true)) {
                    $classes[$current][] = ['name' => $name->text, 'static' => in_array(T_STATIC, $modifiers, true)];
                }

                $modifiers = [];
            }
        }

        return $classes;
    }

    /**
     * @param  array<int, PhpToken>  $tokens
     */
    private function nextMeaningful(array $tokens, int $index): ?PhpToken
    {
        for ($i = $index + 1; $i < count($tokens); $i++) {
            if (! $tokens[$i]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                return $tokens[$i];
            }
        }

        return null;
    }

    /**
     * @param  array<int, PhpToken>  $tokens
     */
    private function previousMeaningful(array $tokens, int $index): ?PhpToken
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            if (! $tokens[$i]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                return $tokens[$i];
            }
        }

        return null;
    }

    /**
     * @return array<int, array{kind: string, file: string, subject: string}>
     */
    private function routeViolations(string $path): array
    {
        $file = $this->relative($path);
        $source = implode('', array_map(fn (PhpToken $token): string => $token->is([T_COMMENT, T_DOC_COMMENT]) ? '' : $token->text, PhpToken::tokenize((string) file_get_contents($path))));
        $violations = [];
        $verbs = 'get|post|put|patch|delete|options|any';
        $head = "Route::(?:(?:{$verbs})\\(|match\\(\\s*\\[[^\\]]*\\]\\s*,)";

        preg_match_all("/{$head}\s*(['\"])([^'\"]*)\\1\s*,\s*\[\s*[\w\\\\]+::class\s*,\s*['\"](\w+)['\"]\s*\]/", $source, $tuples, PREG_SET_ORDER);

        foreach ($tuples as $match) {
            $violations[] = ['kind' => 'flat-tuple', 'file' => $file, 'subject' => "{$match[2]} => [{$match[3]}]"];
        }

        preg_match_all("/{$head}\s*(['\"])([^'\"]*)\\1\s*,\s*['\"][\w\\\\]+@(\w+)['\"]/", $source, $strings, PREG_SET_ORDER);

        foreach ($strings as $match) {
            $violations[] = ['kind' => 'action-string', 'file' => $file, 'subject' => "{$match[2]} => @{$match[3]}"];
        }

        preg_match_all("/{$head}\s*(['\"])([^'\"]*)\\1\s*,\s*(['\"])(\w+)\\3/", $source, $routed, PREG_SET_ORDER);

        foreach ($routed as $match) {
            if (! in_array($match[4], self::ACTIONS, true)) {
                $violations[] = ['kind' => 'routed-method', 'file' => $file, 'subject' => "{$match[2]} => {$match[4]}"];
            }
        }

        preg_match_all('/Route::(resource|apiResource|singleton|apiSingleton)\(\s*([\'"])([^\'"]*)\2/', $source, $resources, PREG_SET_ORDER);

        foreach ($resources as $match) {
            $violations[] = ['kind' => 'resource-route', 'file' => $file, 'subject' => "{$match[1]}({$match[3]})"];
        }

        preg_match_all("/Route::(?:post|put|patch|delete)\(\s*(['\"])([^'\"]*\/(?:".self::VERBS.")[-\w]*)\\1/", $source, $verbUris, PREG_SET_ORDER);

        foreach ($verbUris as $match) {
            $violations[] = ['kind' => 'verb-uri', 'file' => $file, 'subject' => $match[2]];
        }

        return $violations;
    }

    /**
     * Whether the public method overrides a method a VENDOR parent declares by that exact name (a controller extending
     * Passport's AuthorizationController and overriding authorize(), say). The framework calls it, so it is not the app's surface.
     * Everything is read statically: the parent is resolved through the app's composer autoload files (vendor/composer
     * autoload_classmap.php, then autoload_psr4.php), never loaded. Only a parent whose file lies under vendor/ counts, and the
     * walk follows vendor ancestors only. Anything unresolvable or unreadable (no vendor dir, no map entry, an app-owned parent,
     * a parse error) keeps the method a violation. Methods a vendor parent gets only from a trait or an interface do not count.
     * Public so rule 21's body checks (ControllerBodies) skip `response negotiation` under exactly these conditions; $path is absolute.
     */
    public function overridesVendor(string $path, string $class, string $method): bool
    {
        $declaration = $this->declaration($path, $class);
        $vendor = realpath($this->base.'/vendor');

        if ($declaration === null || $vendor === false) {
            return false;
        }

        $parent = $declaration->extends?->toString();

        for ($depth = 0; $parent !== null && $depth < 20; $depth++) {
            $file = $this->autoloaded(ltrim($parent, '\\'), $vendor);

            if ($file === null || ! str_starts_with($file, $vendor.DIRECTORY_SEPARATOR)) {
                return false;
            }

            $parentClass = $this->declaration($file, substr(strrchr('\\'.ltrim($parent, '\\'), '\\') ?: '', 1), ltrim($parent, '\\'));

            if ($parentClass === null) {
                return false;
            }

            foreach ($parentClass->getMethods() as $declared) {
                if ($declared->name->toString() === $method) {
                    return true;
                }
            }

            $parent = $parentClass->extends?->toString();
        }

        return false;
    }

    /** The class node named $short (and, when given, with exactly that fully qualified name) declared in the file; null if absent or unparsable. */
    private function declaration(string $file, string $short, ?string $qualified = null): ?Node\Stmt\Class_
    {
        try {
            $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse((string) file_get_contents($file)) ?? [];
        } catch (ParserError) {
            return null;
        }

        $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes);
        $matches = (new NodeFinder)->find($nodes, fn (Node $node): bool => $node instanceof Node\Stmt\Class_ && $node->name?->toString() === $short
            && ($qualified === null || (isset($node->namespacedName) && $node->namespacedName->toString() === $qualified)));

        return count($matches) === 1 && $matches[0] instanceof Node\Stmt\Class_ ? $matches[0] : null;
    }

    /** @var array{classmap: array<string, string>, psr4: array<string, list<string>>}|null|false */
    private array|null|false $autoload = null;

    /** The real path composer's static maps give the class (classmap first, then PSR-4), or null. The maps are data files; no class is loaded. */
    private function autoloaded(string $class, string $vendor): ?string
    {
        if ($this->autoload === null) {
            $this->autoload = false;
            $classmap = $vendor.'/composer/autoload_classmap.php';
            $psr4 = $vendor.'/composer/autoload_psr4.php';

            if (is_file($classmap) || is_file($psr4)) {
                $read = static fn (string $file): array => is_file($file) && is_array($map = (static fn () => require $file)()) ? $map : [];
                $this->autoload = ['classmap' => $read($classmap), 'psr4' => $read($psr4)];
            }
        }

        if ($this->autoload === false) {
            return null;
        }

        if (isset($this->autoload['classmap'][$class]) && is_string($this->autoload['classmap'][$class])) {
            return realpath($this->autoload['classmap'][$class]) ?: null;
        }

        $prefixes = array_keys($this->autoload['psr4']);
        usort($prefixes, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($prefixes as $prefix) {
            if (! str_starts_with($class, $prefix)) {
                continue;
            }

            foreach ((array) $this->autoload['psr4'][$prefix] as $directory) {
                $file = realpath(rtrim((string) $directory, '/\\').'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php');

                if ($file !== false) {
                    return $file;
                }
            }
        }

        return null;
    }

    private function relative(string $absolute): string
    {
        $base = realpath($this->base) ?: $this->base;

        return str_replace('\\', '/', substr($absolute, strlen($base) + 1));
    }
}
