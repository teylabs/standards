<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use Illuminate\Support\Str;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use Symfony\Component\Finder\Finder;

/**
 * Statically enumerates the mutating routes an app declares in its route files, without booting the app.
 *
 * Only `Route::` facade calls with literal URIs are understood. Anything the parser cannot resolve is returned
 * as an error, so rule 55 fails closed instead of treating an unreadable route as covered.
 */
final class McpRoutes
{
    private const MUTATING = ['POST', 'PUT', 'PATCH', 'DELETE'];

    private const VERBS = ['post' => ['POST'], 'put' => ['PUT'], 'patch' => ['PATCH'], 'delete' => ['DELETE'], 'any' => ['POST', 'PUT', 'PATCH', 'DELETE']];

    private const RESOURCE = ['store' => ['POST', ''], 'update' => ['PUT|PATCH', '/{id}'], 'destroy' => ['DELETE', '/{id}']];

    /** @var list<array{path: string, line: int, methods: string, uri: string, name: ?string, action: ?string, middleware: list<string>}> */
    public array $routes = [];

    /** @var list<array{target: string, check: string, line?: int}> */
    public array $errors = [];

    /**
     * @param  list<string>  $roots
     * @param  array<string, string>|null  $sources  path => contents to parse instead of the files under $roots (standards:make's planned writes)
     */
    public function __construct(string $base, array $roots, ?array $sources = null)
    {
        if ($sources !== null) {
            ksort($sources);
            foreach ($sources as $path => $contents) {
                $this->parse($path, $contents);
            }

            return;
        }
        $files = [];
        foreach ($roots as $root) {
            foreach (glob(rtrim($base, '/').'/'.$root, GLOB_ONLYDIR) ?: [] as $directory) {
                foreach ((new Finder)->files()->in($directory)->name('*.php')->exclude(['vendor', 'node_modules']) as $file) {
                    $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen(rtrim($base, '/')) + 1));
                }
            }
        }
        $files = array_values(array_unique($files));
        sort($files);
        if ($files === []) {
            $this->errors[] = ['target' => $roots === [] ? 'routeRoots' : implode(', ', $roots), 'check' => 'mutating routes could not be enumerated: no route files under the configured routeRoots'];

            return;
        }
        foreach ($files as $path) {
            $this->parse($path, (string) file_get_contents($base.'/'.$path));
        }
    }

    /**
     * The keys a capability's `routes` may use for a route: its name, and "METHOD /uri" for each of its methods.
     *
     * @param  array{methods: string, uri: string, name: ?string}  $route
     * @return list<string>
     */
    public static function keys(array $route): array
    {
        return [...($route['name'] === null ? [] : [$route['name']]), ...array_map(fn (string $method): string => $method.' '.$route['uri'], explode('|', $route['methods']))];
    }

    private function parse(string $path, string $contents): void
    {
        try {
            $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($contents) ?? [];
        } catch (Error) {
            $this->errors[] = ['target' => $path, 'check' => 'mutating routes could not be enumerated: the route file does not parse'];

            return;
        }
        $nodes = (new NodeTraverser(new NameResolver, new ParentConnectingVisitor))->traverse($nodes);
        foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Expr\StaticCall::class) as $call) {
            $this->route($path, $call);
        }
    }

    private function route(string $path, Node\Expr\StaticCall $call): void
    {
        if (! $call->class instanceof Node\Name || ! in_array(ltrim($call->class->toString(), '\\'), ['Route', 'Illuminate\Support\Facades\Route'], true)) {
            return;
        }
        if (! $call->name instanceof Node\Identifier) {
            $this->errors[] = ['target' => $path, 'check' => 'mutating routes could not be enumerated: a Route call uses a dynamic method name', 'line' => $call->getStartLine()];

            return;
        }
        $verb = strtolower($call->name->toString());
        $args = $call->getArgs();
        $resource = in_array($verb, ['resource', 'apiresource'], true);
        if (! isset(self::VERBS[$verb]) && $verb !== 'match' && ! $resource) {
            return;
        }
        if ($verb === 'match') {
            $methods = $this->strings($args[0]->value ?? null);
            if ($methods === null) {
                $this->errors[] = ['target' => $path, 'check' => 'mutating routes could not be enumerated: Route::match has non-literal methods', 'line' => $call->getStartLine()];

                return;
            }
            $methods = array_values(array_intersect(self::MUTATING, array_map(strtoupper(...), $methods)));
            if ($methods === []) {
                return;
            }
        }
        $uriArg = $args[$verb === 'match' ? 1 : 0]->value ?? null;
        if (! $uriArg instanceof Node\Scalar\String_) {
            $this->errors[] = ['target' => $path, 'check' => 'mutating routes could not be enumerated: Route::'.$call->name->toString().' has a non-literal URI', 'line' => $call->getStartLine()];

            return;
        }
        [$chain, $name] = $this->chain($call);
        [$prefix, $namePrefix, $middleware, $controller] = $this->groups($call);
        $middleware = [...$middleware, ...($chain['middleware'] ?? [])];
        $uri = $this->join($prefix, $uriArg->value);
        if ($resource) {
            $actions = array_keys(self::RESOURCE);
            if (isset($chain['only'])) {
                $actions = array_values(array_intersect($actions, $chain['only']));
            }
            if (isset($chain['except'])) {
                $actions = array_values(array_diff($actions, $chain['except']));
            }
            $base = str_replace('/', '.', trim($uriArg->value, '/'));
            $class = $this->controller($args[1]->value ?? null) ?? $controller;
            foreach ($actions as $action) {
                [$methods, $suffix] = self::RESOURCE[$action];
                $this->routes[] = ['path' => $path, 'line' => $call->getStartLine(), 'methods' => $methods, 'uri' => $suffix === '' ? $uri : $uri.'/{'.Str::singular(Str::afterLast(trim($uriArg->value, '/'), '/')).'}', 'name' => $namePrefix.$base.'.'.$action, 'action' => $class === null ? null : $class.'@'.$action, 'middleware' => $middleware];
            }

            return;
        }
        $action = $args[$verb === 'match' ? 2 : 1]->value ?? null;
        $handler = match (true) {
            $action instanceof Node\Scalar\String_ && $controller !== null => $controller.'@'.$action->value,
            $action instanceof Node\Scalar\String_ => $action->value,
            $action instanceof Node\Expr\Array_ && count($action->items) === 2 && ($action->items[1]->value ?? null) instanceof Node\Scalar\String_ => ($this->controller($action->items[0]->value ?? null) ?? '?').'@'.$action->items[1]->value->value,
            default => $this->controller($action),
        };
        $this->routes[] = ['path' => $path, 'line' => $call->getStartLine(), 'methods' => implode('|', $methods ?? self::VERBS[$verb]), 'uri' => $uri, 'name' => $name === null ? null : $namePrefix.$name, 'action' => $handler, 'middleware' => $middleware];
    }

    /**
     * The fluent calls chained onto a route definition (->name(), ->middleware(), ->only(), ->except()).
     *
     * @return array{0: array<string, list<string>>, 1: ?string}
     */
    private function chain(Node\Expr $call): array
    {
        $chain = [];
        $name = null;
        $node = $call;
        while (($parent = $node->getAttribute('parent')) instanceof Node\Expr\MethodCall && $parent->var === $node) {
            $method = $parent->name instanceof Node\Identifier ? strtolower($parent->name->toString()) : '';
            $values = [];
            foreach ($parent->getArgs() as $arg) {
                $values = [...$values, ...($this->strings($arg->value) ?? $this->names($arg->value))];
            }
            if ($method === 'name' && isset($values[0])) {
                $name = $values[0];
            } else {
                $chain[$method] = [...($chain[$method] ?? []), ...$values];
            }
            $node = $parent;
        }

        return [$chain, $name];
    }

    /**
     * Prefix, name prefix, middleware and controller from every enclosing Route group, outermost first.
     *
     * @return array{0: string, 1: string, 2: list<string>, 3: ?string}
     */
    private function groups(Node $node): array
    {
        $prefix = $names = '';
        $middleware = [];
        $controller = null;
        $layers = [];
        while (($node = $node->getAttribute('parent')) !== null) {
            if ($node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier && strtolower($node->name->toString()) === 'group') {
                $layers[] = $node->var;
            }
        }
        foreach (array_reverse($layers) as $layer) {
            $calls = [];
            for ($link = $layer; $link instanceof Node\Expr\MethodCall || $link instanceof Node\Expr\StaticCall; $link = $link instanceof Node\Expr\MethodCall ? $link->var : null) {
                $calls[] = $link;
            }
            foreach (array_reverse($calls) as $link) {
                $method = $link->name instanceof Node\Identifier ? strtolower($link->name->toString()) : '';
                $value = $link->getArgs()[0]->value ?? null;
                match ($method) {
                    'prefix' => $prefix = $value instanceof Node\Scalar\String_ ? $this->join($prefix, $value->value) : $prefix,
                    'name', 'as' => $names .= $value instanceof Node\Scalar\String_ ? $value->value : '',
                    'middleware' => $middleware = [...$middleware, ...array_merge(...array_map(fn (Node\Arg $arg): array => $this->strings($arg->value) ?? $this->names($arg->value), $link->getArgs()))],
                    'controller' => $controller = $this->controller($value) ?? $controller,
                    default => null,
                };
            }
        }

        return [$prefix, $names, $middleware, $controller];
    }

    private function join(string $prefix, string $uri): string
    {
        $joined = trim(trim($prefix, '/').'/'.trim($uri, '/'), '/');

        return '/'.$joined;
    }

    private function controller(?Node\Expr $value): ?string
    {
        return $value instanceof Node\Expr\ClassConstFetch && $value->class instanceof Node\Name ? ltrim($value->class->toString(), '\\') : null;
    }

    /** @return list<string>|null */
    private function strings(?Node\Expr $value): ?array
    {
        if ($value instanceof Node\Scalar\String_) {
            return [$value->value];
        }
        if (! $value instanceof Node\Expr\Array_) {
            return null;
        }
        $strings = [];
        foreach ($value->items as $item) {
            if (! ($item?->value ?? null) instanceof Node\Scalar\String_) {
                return null;
            }
            $strings[] = $item->value->value;
        }

        return $strings;
    }

    /** @return list<string> class names used as middleware (Foo::class), for signature detection */
    private function names(?Node\Expr $value): array
    {
        $items = $value instanceof Node\Expr\Array_ ? array_map(fn (?Node\ArrayItem $item): ?Node\Expr => $item?->value, $value->items) : [$value];

        return array_values(array_filter(array_map(fn (?Node\Expr $item): ?string => $item instanceof Node\Scalar\String_ ? $item->value : $this->controller($item), $items)));
    }
}
