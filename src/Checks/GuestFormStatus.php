<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Tey\Standards\Configuration;
use Tey\Standards\Source;

/**
 * Whether a guest form's `back()` provably lands on a page that renders tey/kit's `<FormStatus>` (kit 0.5.65), which then replaces the toast.
 *
 * The narrowest resolution, all of which must hold or the toast is still required:
 *  0. bootstrap/app.php exists, parses, and its withMiddleware configurator adds only SAFE_MIDDLEWARE (globally or to any group); app providers
 *     neither wrap nor register routes nor push unknown middleware to a group (environmentKeepsGuest()).
 *  1. Every route that registers Controller@action is found in the route roots, is a literal `Route::post|put|patch|delete(<literal uri>, ...)`
 *     (array, invokable class or group-controller string action), and every middleware on it and its enclosing groups is a literal from
 *     GUEST_MIDDLEWARE. Any other middleware, a non-literal one, a non-literal prefix, a `Route::group([...])` array, or an include/require inside a
 *     group anywhere in the route roots makes it unprovable. A controller that declares `middleware()` or calls `$this->middleware()` is never guest.
 *  2. For each such route, exactly one GET route with the same full literal URI exists: a controller action (the same controller, or one
 *     class declared exactly once under the controller roots), or `Route::inertia('<uri>', '<literal page>')`.
 *     A literal named-route redirect (`to_route('<name>')`, `redirect()->route('<name>')`, `Redirect::route('<name>')`, one argument only;
 *     redirectFlash()) lands instead on the one route registered under that full name (group `name()`/`as()` prefixes plus the route's own,
 *     routeName()): it must be a guest GET with a literal URI and no parameters, and no route in the route roots may carry an unreadable name
 *     (a non-literal name, a `Route::group([...])`, a resource, match or any registration).
 *  3. Every return of that GET action is `Inertia::render('<literal>')` or `inertia('<literal>')`, all the same page (Route::inertia names it).
 *  4. That page is exactly one `<page>.vue` under the page roots, and it, or a `.vue` file it imports (app- or kit-owned, up to three import levels,
 *     `@/` and relative specifiers only, cycles cut), has a script that imports FormStatus from `@/components/Kit` or `@/components/Kit/Form`
 *     and a template that renders `<FormStatus`.
 *  5. Each session key the back() flashes (`->with('<key>', ...)` or `->with(['<key>' => ...])`, literal identifier keys only) is shown by a
 *     `<FormStatus>` in that tree whose `:message` reads it (keyReferenced()). A key set but not read, or a non-literal key, fails.
 */
final class GuestFormStatus
{
    /** Literal middleware that does not sign anyone in. */
    public const GUEST_MIDDLEWARE = ['web', 'guest', 'signed', 'throttle', 'precognitive'];

    private const MUTATING = ['post', 'put', 'patch', 'delete'];

    /** Import levels below the page the FormStatus search follows. */
    private const LEVELS = 3;

    /** Middleware classes known not to sign anyone in, allowed when bootstrap/app.php or a provider adds them to a group. */
    public const SAFE_MIDDLEWARE = [
        '*\\HandleInertiaRequests',
        'Tey\\Kit\\Features\\SessionUi\\Http\\Middleware\\PrepareKitRequest',
        'Illuminate\\Http\\Middleware\\AddLinkHeadersForPreloadedAssets',
    ];

    /** Middleware configurator calls that add nothing to a group a web route runs through. */
    private const HARMLESS = ['redirectgueststo', 'redirectto', 'redirectusersto', 'validatecsrftokens', 'preventrequestsduringmaintenance', 'encryptcookies', 'convertemptystringstonull', 'trimstrings', 'trustproxies', 'trusthosts', 'throttleapi', 'statefulapi', 'api', 'priority', 'appendtoprioritylist', 'prependtoprioritylist', 'remove', 'removefromgroup'];

    /** Configurator calls whose every argument must be allowlisted middleware. */
    private const ADDING = ['append', 'prepend', 'appendtogroup', 'prependtogroup', 'group', 'use', 'web'];

    private ?bool $environment = null;

    /** @var ?list<array{verbs: list<string>, page: ?string, uri: ?string, class: ?string, method: ?string, guest: bool, name: ?string}> */
    private ?array $routes = null;

    public function __construct(private readonly Configuration $config, private readonly Source $source) {}

    /**
     * @param  list<string>  $keys  session keys the redirect flashes; each must be shown by a FormStatus in the page's tree (keyReferenced())
     * @param  ?string  $route  the literal route name a `to_route()` / `redirect()->route()` / `Redirect::route()` names; null for `back()`
     */
    public function proves(Node\Stmt\Class_ $class, string $action, array $keys = [], ?string $route = null): bool
    {
        if (! isset($class->namespacedName) || self::declaresMiddleware($class)) {
            return false;
        }
        if (! $this->environmentKeepsGuest()) {
            return false;
        }
        $controller = $class->namespacedName->toString();
        $routes = $this->routes();
        $mutations = array_filter($routes, fn (array $route): bool => $route['class'] === $controller && in_array($route['method'], [$action, '*'], true) && array_intersect($route['verbs'], self::MUTATING) !== []);
        if ($mutations === []) {
            return false;
        }
        foreach ($mutations as $mutation) {
            if (! $mutation['guest'] || $mutation['uri'] === null) {
                return false;
            }
            if ($route !== null) {
                // The named target: exactly one registration carries the name, none carries a name this reader cannot read, and it is a guest
                // GET with a literal, parameterless URI.
                $pages = array_values(array_filter($routes, fn (array $candidate): bool => $candidate['name'] === $route));
                if (in_array(null, array_column($routes, 'name'), true) || count($pages) !== 1 || $pages[0]['verbs'] !== ['get'] || ! $pages[0]['guest'] || $pages[0]['uri'] === null || str_contains($pages[0]['uri'], '{')) {
                    return false;
                }
            } else {
                $pages = array_values(array_filter($routes, fn (array $candidate): bool => in_array('get', $candidate['verbs'], true) && ($candidate['uri'] === null || $candidate['uri'] === $mutation['uri'])));
                if (count($pages) !== 1 || $pages[0]['uri'] === null) {
                    return false;
                }
            }
            if ($pages[0]['page'] !== null) {
                $page = $pages[0]['page'];
            } else {
                $show = $pages[0]['class'] === $controller ? $class : ($pages[0]['class'] === null ? null : $this->controllerClass($pages[0]['class']));
                $page = $show === null || $pages[0]['method'] === null ? null : $this->renderedPage($show, $pages[0]['method']);
            }
            if ($page === null || ! $this->rendersFormStatus($page, $keys)) {
                return false;
            }
        }

        return true;
    }

    /** `back()` or `redirect()->back()`, without anything chained but `->withInput()` / `->withErrors()`. */
    public static function isBack(Node\Expr $expression): bool
    {
        return self::backFlashKeys($expression) === [];
    }

    /**
     * The session keys a `back()` / `redirect()->back()` flashes through `->with('<key>', ...)` or `->with(['<key>' => ...])`, in a chain that
     * holds nothing else but `->withInput()` / `->withErrors()`; [] for a plain back(). Null when it is not such a chain, or when any key is
     * not a literal identifier (`flash.<key>` must be able to name it).
     *
     * @return ?list<string>
     */
    public static function backFlashKeys(Node\Expr $expression): ?array
    {
        $target = self::redirectFlash($expression);

        return $target !== null && $target[1] === null ? $target[0] : null;
    }

    /**
     * backFlashKeys() widened to a named-route redirect: `to_route('<name>')`, `redirect()->route('<name>')` or `Redirect::route('<name>')`
     * with exactly one argument, a literal name. Returns [keys, route name], the name null for a back(); null for anything else (a non-literal
     * name, route parameters or any other argument).
     *
     * @return ?array{list<string>, ?string}
     */
    public static function redirectFlash(Node\Expr $expression): ?array
    {
        $keys = [];
        while ($expression instanceof Node\Expr\MethodCall && $expression->name instanceof Node\Identifier && in_array(strtolower($expression->name->toString()), ['withinput', 'witherrors', 'with'], true)) {
            if (strtolower($expression->name->toString()) === 'with') {
                $named = self::withKeys($expression);
                if ($named === null) {
                    return null;
                }
                $keys = [...$keys, ...$named];
            }
            $expression = $expression->var;
        }
        $redirector = $expression instanceof Node\Expr\MethodCall && $expression->name instanceof Node\Identifier
            && $expression->var instanceof Node\Expr\FuncCall && $expression->var->name instanceof Node\Name && $expression->var->name->toString() === 'redirect' && $expression->var->getArgs() === [];
        $back = ($expression instanceof Node\Expr\FuncCall && $expression->name instanceof Node\Name && $expression->name->toString() === 'back')
            || ($redirector && strtolower($expression->name->toString()) === 'back');
        if ($back) {
            return [array_values(array_unique($keys)), null];
        }
        $named = ($expression instanceof Node\Expr\FuncCall && $expression->name instanceof Node\Name && $expression->name->toString() === 'to_route')
            || ($redirector && strtolower($expression->name->toString()) === 'route')
            || ($expression instanceof Node\Expr\StaticCall && $expression->class instanceof Node\Name && $expression->class->toString() === 'Illuminate\Support\Facades\Redirect'
                && $expression->name instanceof Node\Identifier && strtolower($expression->name->toString()) === 'route');
        if (! $named || $expression->isFirstClassCallable()) {
            return null;
        }
        $arguments = $expression->getArgs();
        if (count($arguments) !== 1 || $arguments[0]->unpack || $arguments[0]->name !== null || ! $arguments[0]->value instanceof Node\Scalar\String_ || $arguments[0]->value->value === '') {
            return null;
        }

        return [array_values(array_unique($keys)), $arguments[0]->value->value];
    }

    /** @return ?list<string> the literal keys one `->with(...)` or `Inertia::flash(...)` flashes, or null */
    public static function withKeys(Node\Expr\MethodCall|Node\Expr\StaticCall $call): ?array
    {
        $arguments = $call->getArgs();
        $first = $arguments[0] ?? null;
        if ($call->isFirstClassCallable() || $first === null || $first->unpack || $first->name !== null) {
            return null;
        }
        $keys = [];
        if ($first->value instanceof Node\Scalar\String_ && count($arguments) === 2) {
            $keys[] = $first->value;
        } elseif ($first->value instanceof Node\Expr\Array_ && count($arguments) === 1 && $first->value->items !== []) {
            foreach ($first->value->items as $item) {
                if ($item === null || $item->unpack || ! $item->key instanceof Node\Scalar\String_) {
                    return null;
                }
                $keys[] = $item->key;
            }
        } else {
            return null;
        }
        $names = [];
        foreach ($keys as $key) {
            if (preg_match('~\A[A-Za-z_$][\w$]*\z~', $key->value) !== 1) {
                return null;
            }
            $names[] = $key->value;
        }

        return $names;
    }

    /**
     * Whether bootstrap/app.php and the app's service providers provably add nothing but SAFE_MIDDLEWARE to the groups a route runs through.
     * A missing or unparsable bootstrap/app.php, a non-closure or escaping `withMiddleware` configurator, an unknown configurator call, `withRouting`
     * with `then:` or `using:`, an alias over a GUEST_MIDDLEWARE name, or a provider that wraps or registers routes fails closed.
     */
    private function environmentKeepsGuest(): bool
    {
        return $this->environment ??= $this->bootstrapKeepsGuest() && $this->providersKeepGuest();
    }

    private function bootstrapKeepsGuest(): bool
    {
        if (! is_file($this->config->base.'/bootstrap/app.php')) {
            return false;
        }
        try {
            $nodes = $this->source->nodes('bootstrap/app.php');
        } catch (\PhpParser\Error) {
            return false;
        }
        if ($nodes === []) {
            return false;
        }
        $finder = new NodeFinder;
        foreach ($finder->find($nodes, fn (Node $node): bool => $node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier) as $call) {
            /** @var Node\Expr\MethodCall $call */
            $name = strtolower($call->name->toString());
            if ($name === 'withrouting' && array_filter($call->getArgs(), fn (Node\Arg $argument): bool => in_array($argument->name?->toString(), ['then', 'using'], true)) !== []) {
                return false;
            }
            if ($name !== 'withmiddleware') {
                continue;
            }
            $configure = $call->getArgs()[0]->value ?? null;
            if ($call->getArgs() === []) {
                continue;
            }
            if (! ($configure instanceof Node\Expr\Closure || $configure instanceof Node\Expr\ArrowFunction) || ! ($configure->params[0]->var ?? null) instanceof Node\Expr\Variable) {
                return false;
            }
            $parameter = $configure->params[0]->var->name;
            foreach ($finder->find($configure->getStmts() ?? [$configure->expr], fn (Node $node): bool => $node instanceof Node\Expr\Variable && $node->name === $parameter) as $use) {
                $call = $use->getAttribute('parent');
                if (! $call instanceof Node\Expr\MethodCall || $call->var !== $use || ! $call->name instanceof Node\Identifier || ! $this->safeConfiguratorCall($call)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function safeConfiguratorCall(Node\Expr\MethodCall $call): bool
    {
        $name = strtolower($call->name->toString());
        if (in_array($name, self::HARMLESS, true)) {
            return true;
        }
        if ($name === 'alias') {
            $map = $call->getArgs()[0]->value ?? null;
            if (! $map instanceof Node\Expr\Array_) {
                return false;
            }
            foreach ($map->items as $item) {
                if (! $item?->key instanceof Node\Scalar\String_ || in_array(explode(':', $item->key->value)[0], self::GUEST_MIDDLEWARE, true)) {
                    return false;
                }
            }

            return true;
        }
        if (! in_array($name, self::ADDING, true)) {
            return false;
        }
        foreach ($call->getArgs() as $index => $argument) {
            $label = $argument->name?->toString();
            // appendToGroup('web', ...) and group('web', [...]) name the group first; web(remove: [...]) removes.
            if (($index === 0 && $label === null && in_array($name, ['appendtogroup', 'prependtogroup', 'group'], true) && $argument->value instanceof Node\Scalar\String_) || $label === 'remove' || $label === 'group') {
                continue;
            }
            if (! self::safeMiddleware($argument->value)) {
                return false;
            }
        }

        return true;
    }

    /** A literal Class::class (or a list of them) on SAFE_MIDDLEWARE. */
    public static function safeMiddleware(?Node\Expr $value): bool
    {
        if ($value instanceof Node\Expr\Array_) {
            foreach ($value->items as $item) {
                if ($item === null || $item->key !== null && ! $item->key instanceof Node\Scalar\String_ || ! self::safeMiddleware($item->value)) {
                    return false;
                }
            }

            return true;
        }
        $class = $value instanceof Node\Expr\ClassConstFetch && $value->class instanceof Node\Name && $value->name instanceof Node\Identifier && strtolower($value->name->toString()) === 'class'
            ? $value->class->toString()
            : ($value instanceof Node\Scalar\String_ ? ltrim($value->value, '\\') : null);
        if ($class === null) {
            return false;
        }
        foreach (self::SAFE_MIDDLEWARE as $safe) {
            if ($class === $safe || (str_starts_with($safe, '*\\') && str_ends_with('\\'.$class, substr($safe, 1)))) {
                return true;
            }
        }

        return false;
    }

    /** Providers under app/Providers or listed in bootstrap/providers.php that touch route middleware or register route files. */
    private function providersKeepGuest(): bool
    {
        $paths = array_keys($this->source->files(['app/Providers']));
        if (is_file($this->config->base.'/bootstrap/providers.php')) {
            try {
                $listed = (new NodeFinder)->findInstanceOf($this->source->nodes('bootstrap/providers.php'), Node\Expr\ClassConstFetch::class);
            } catch (\PhpParser\Error) {
                return false;
            }
            $declared = $this->source->declared();
            foreach ($listed as $constant) {
                if ($constant->class instanceof Node\Name && isset($declared[$constant->class->toString()])) {
                    $paths[] = $declared[$constant->class->toString()];
                }
            }
        }
        foreach (array_unique($paths) as $path) {
            try {
                $nodes = $this->source->nodes($path);
            } catch (\PhpParser\Error) {
                return false;
            }
            foreach ((new NodeFinder)->find($nodes, fn (Node $node): bool => $node instanceof Node\Expr\Include_ || $node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall) as $node) {
                if ($node instanceof Node\Expr\Include_) {
                    return false;
                }
                /** @var Node\Expr\MethodCall|Node\Expr\StaticCall $node */
                $name = $node->name instanceof Node\Identifier ? strtolower($node->name->toString()) : null;
                $route = $node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name && $node->class->toString() === 'Illuminate\Support\Facades\Route';
                // Any group() (Route::group, Route::middleware(...)->group(...)) or routes() wraps or registers route files.
                if (in_array($name, ['loadroutesfrom', 'group', 'routes'], true) || ($route && $name === null)) {
                    return false;
                }
                if (($route && $name === 'middleware') && ! self::safeMiddleware($node->getArgs()[0]->value ?? null)) {
                    return false;
                }
                if (in_array($name, ['pushmiddlewaretogroup', 'prependmiddlewaretogroup', 'appendmiddlewaretogroup', 'middlewaregroup'], true) && ! self::safeMiddleware($node->getArgs()[1]->value ?? null)) {
                    return false;
                }
                if ($name === 'aliasmiddleware') {
                    $alias = $node->getArgs()[0]->value ?? null;
                    if (! $alias instanceof Node\Scalar\String_ || in_array(explode(':', $alias->value)[0], self::GUEST_MIDDLEWARE, true)) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    private static function declaresMiddleware(Node\Stmt\Class_ $class): bool
    {
        if ($class->getMethod('middleware') !== null) {
            return true;
        }

        return (new NodeFinder)->findFirst($class->stmts, fn (Node $node): bool => $node instanceof Node\Expr\MethodCall && $node->var instanceof Node\Expr\Variable && $node->var->name === 'this'
            && $node->name instanceof Node\Identifier && strtolower($node->name->toString()) === 'middleware') !== null;
    }

    /** @var array<string, ?Node\Stmt\Class_> */
    private array $classes = [];

    /** The one class declaration named `$name` under the controller roots, or null when there is none or more than one. */
    private function controllerClass(string $name): ?Node\Stmt\Class_
    {
        if (array_key_exists($name, $this->classes)) {
            return $this->classes[$name];
        }
        $found = [];
        foreach ($this->source->files($this->config->controllerRoots) as $path => $text) {
            foreach ((new NodeFinder)->findInstanceOf($this->source->nodes($path), Node\Stmt\Class_::class) as $candidate) {
                if (isset($candidate->namespacedName) && $candidate->namespacedName->toString() === $name) {
                    $found[] = $candidate;
                }
            }
        }

        return $this->classes[$name] = count($found) === 1 ? $found[0] : null;
    }

    /** The one literal page every return of the action renders, or null. */
    private function renderedPage(Node\Stmt\Class_ $class, string $method): ?string
    {
        $action = $class->getMethod($method);
        if ($action === null || $action->stmts === null) {
            return null;
        }
        $returns = (new NodeFinder)->find($action->stmts, fn (Node $node): bool => $node instanceof Node\Stmt\Return_);
        $pages = [];
        foreach ($returns as $return) {
            $closure = false;
            for ($parent = $return->getAttribute('parent'); $parent instanceof Node && $parent !== $action; $parent = $parent->getAttribute('parent')) {
                $closure = $closure || $parent instanceof Node\FunctionLike;
            }
            if ($closure) {
                continue;
            }
            $expression = $return->expr;
            $render = ($expression instanceof Node\Expr\StaticCall && $expression->class instanceof Node\Name && $expression->class->toString() === 'Inertia\Inertia' && $expression->name instanceof Node\Identifier && strtolower($expression->name->toString()) === 'render')
                || ($expression instanceof Node\Expr\FuncCall && $expression->name instanceof Node\Name && $expression->name->toString() === 'inertia' && $expression->getArgs() !== []);
            $name = $render ? ($expression->getArgs()[0]->value ?? null) : null;
            if (! $name instanceof Node\Scalar\String_) {
                return null;
            }
            $pages[$name->value] = true;
        }

        return count($pages) === 1 ? (string) array_key_first($pages) : null;
    }

    /** @param list<string> $keys */
    private function rendersFormStatus(string $page, array $keys): bool
    {
        if (str_contains($page, '..') || $page === '') {
            return false;
        }
        $files = [];
        foreach ($this->config->pageRoots as $root) {
            foreach (glob($this->config->base.'/'.$root, GLOB_ONLYDIR) ?: [] as $directory) {
                if (is_file($directory.'/'.$page.'.vue')) {
                    $files[] = $directory.'/'.$page.'.vue';
                }
            }
        }
        if (count(array_unique($files)) !== 1) {
            return false;
        }
        $page = substr($files[0], strlen($this->config->base) + 1);
        $follow = fn (array $import, string $from): ?string => self::component($this->config->base, $import, $from);
        if ($keys === []) {
            return VueImports::reaches($this->config->base, $page, self::LEVELS, fn (string $path, string $source): bool => self::rendersKitFormStatus($source), $follow);
        }
        foreach ($keys as $key) {
            if (! VueImports::reaches($this->config->base, $page, self::LEVELS, fn (string $path, string $source): bool => self::rendersKitFormStatus($source) && self::keyReferenced($source, $key, $path === $page), $follow)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a `<FormStatus>` in the file reads the flashed key. Its `:message` / `v-bind:message` (FormStatus's text prop in kit) may read it
     * through `flash.<key>`, the Inertia v2 shared prop `$page.props.flash.<key>` / `usePage().props.flash.<key>`, the Inertia v3 page flash
     * `$page.flash.<key>` / `usePage().flash.<key>`, or `page.flash.<key>` / `page.props.flash.<key>` where the script binds
     * `const page = usePage()` (or `let`); `?.` is allowed at every step. usePage() returns a reactive object, not a ref, so `page.value.flash`
     * is not read. On the page itself, `<key>` / `props.<key>` counts when its defineProps declares `<key>`. kit FormStatus's `flash-key` /
     * `flashKey` prop counts when it is the static string `<key>` or bound to the string literal `'<key>'`; a bound variable does not.
     * A bare `<FormStatus />` reads the `status` page prop, which a flash reaches only through shared props this reader does not follow, so it
     * never proves a key.
     */
    public static function keyReferenced(string $source, string $key, bool $page): bool
    {
        $quoted = preg_quote($key, '~');
        $code = VueImports::code(VueImports::scripts($source));
        $roots = ['\\$page', 'usePage\\(\\)'];
        if (preg_match_all('~\\b(?:const|let)\\s+([A-Za-z_$][\\w$]*)\\s*=\\s*usePage\\s*\\(\\s*\\)~', $code, $aliases) > 0) {
            $roots = [...$roots, ...array_map(fn (string $alias): string => preg_quote($alias, '~'), $aliases[1])];
        }
        $flash = '~(?:(?<![\\w$.])(?:'.implode('|', $roots).')\\??\\.(?:props\\??\\.)?|(?<![\\w$.]))flash\\??\\.'.$quoted.'(?![\\w$])~';
        $prop = '~(?<![\\w$.])(?:props\\??\\.)?'.$quoted.'(?![\\w$])~';
        $declared = $page && self::declaresProp($code, $key);
        foreach (self::formStatusMessages($source) as $expression) {
            if (preg_match($flash, $expression) === 1 || ($declared && preg_match($prop, $expression) === 1)) {
                return true;
            }
        }

        return in_array($key, self::formStatusFlashKeys($source), true);
    }

    /** @return list<string> every literal key a `<FormStatus>` names through `flash-key="<key>"` / `flashKey="<key>"` or `:flash-key="'<key>'"` */
    public static function formStatusFlashKeys(string $source): array
    {
        preg_match_all('~<FormStatus\\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>~', FrontendSource::template($source), $tags);
        $keys = [];
        foreach ($tags[1] as $attributes) {
            preg_match_all('~(?<![\\w:.-])(:|v-bind:)?(?:flash-key|flashKey)\\s*=\\s*(?:"([^"]*)"|\'([^\']*)\')~', $attributes, $bindings, PREG_SET_ORDER);
            foreach ($bindings as $binding) {
                $value = ($binding[2] ?? '') !== '' ? $binding[2] : ($binding[3] ?? '');
                if ($binding[1] !== '') {
                    $value = preg_match('~\\A\\s*(?:\'([^\'\\\\]*)\'|"([^"\\\\]*)"|`([^`$\\\\]*)`)\\s*\\z~', $value, $literal) === 1 ? ($literal[1] ?? '').($literal[2] ?? '').($literal[3] ?? '') : '';
                }
                if ($value !== '') {
                    $keys[] = $value;
                }
            }
        }

        return $keys;
    }

    /** @return list<string> the expression of every `:message` / `v-bind:message` on a `<FormStatus>` tag in the template */
    public static function formStatusMessages(string $source): array
    {
        preg_match_all('~<FormStatus\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>~', FrontendSource::template($source), $tags);
        $expressions = [];
        foreach ($tags[1] as $attributes) {
            preg_match_all('~(?<![\w:-])(?::|v-bind:)message\s*=\s*(?:"([^"]*)"|\'([^\']*)\')~', $attributes, $bindings, PREG_SET_ORDER);
            foreach ($bindings as $binding) {
                $expressions[] = ($binding[1] ?? '') !== '' ? $binding[1] : ($binding[2] ?? '');
            }
        }

        return $expressions;
    }

    /** Whether defineProps declares the key: `defineProps<{ key: ... }>()`, `defineProps({ key: ... })` or `defineProps(['key'])`. */
    private static function declaresProp(string $code, string $key): bool
    {
        $quoted = preg_quote($key, '~');
        if (preg_match_all('~\bdefineProps\s*(?:<\s*\{([^}]*)\}\s*>|\(\s*\{([^}]*)\}|\(\s*\[([^\]]*)\])~', $code, $matches, PREG_SET_ORDER) === 0) {
            return false;
        }
        foreach ($matches as $match) {
            $body = ($match[1] ?? '').($match[2] ?? '');
            if (preg_match('~(?:^|[\s,;{])[\'"]?'.$quoted.'[\'"]?\s*\??\s*:~', $body) === 1 || preg_match('~[\'"]'.$quoted.'[\'"]~', $match[3] ?? '') === 1) {
                return true;
            }
        }

        return false;
    }

    /** Whether a `.vue` file's script imports FormStatus (not renamed) from `@/components/Kit` or `@/components/Kit/Form` and its template renders `<FormStatus`. */
    private static function rendersKitFormStatus(string $source): bool
    {
        $imports = preg_match('~\bimport\s*\{[^}]*(?<![\w$])FormStatus(?![\w$])(?!\s+as\b)[^}]*\}\s*from\s*([\'"])@/components/Kit(?:/Form)?\1~', VueImports::code(VueImports::scripts($source))) === 1;

        return $imports && preg_match('~<FormStatus[\s/>]~', FrontendSource::template($source)) === 1;
    }

    /** The `.vue` file an import names, app- or kit-owned, when it resolves under resources/js and exists; null for anything else. @param array{0: string, 1: string} $import */
    private static function component(string $base, array $import, string $from): ?string
    {
        $path = VueImports::resolve($base, $import[0], $from);

        return $path !== null && str_ends_with($path, '.vue') && is_file($base.'/'.$path) && ! is_link($base.'/'.$path) ? $path : null;
    }

    /** @return list<array{verbs: list<string>, page: ?string, uri: ?string, class: ?string, method: ?string, guest: bool, name: ?string}> */
    private function routes(): array
    {
        if ($this->routes !== null) {
            return $this->routes;
        }
        $routes = [];
        $finder = new NodeFinder;
        $files = $this->source->files($this->config->routeRoots);
        // A route file included inside a group inherits middleware this reader does not follow: then nothing is provably guest.
        $includedInGroup = false;
        foreach ($files as $path => $text) {
            foreach ($finder->findInstanceOf($this->source->nodes($path), Node\Expr\Include_::class) as $include) {
                $includedInGroup = $includedInGroup || $this->groups($include) !== [];
            }
        }
        foreach ($files as $path => $text) {
            foreach ($finder->find($this->source->nodes($path), fn (Node $node): bool => ($node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\MethodCall) && $node->name instanceof Node\Identifier) as $call) {
                /** @var Node\Expr\StaticCall|Node\Expr\MethodCall $call */
                $verb = strtolower($call->name->toString());
                if (! in_array($verb, ['get', 'inertia', 'match', 'any', 'resource', 'apiresource', 'resources', 'apiresources', ...self::MUTATING], true) || $call->isFirstClassCallable()) {
                    continue;
                }
                $chain = [];
                $root = $call;
                while ($root instanceof Node\Expr\MethodCall) {
                    $root = $root->var;
                    $chain[] = $root;
                }
                if (! $root instanceof Node\Expr\StaticCall || ! $root->class instanceof Node\Name || $root->class->toString() !== 'Illuminate\Support\Facades\Route') {
                    continue;
                }
                $arguments = $call->getArgs();
                if (! in_array($verb, ['get', 'inertia', ...self::MUTATING], true)) {
                    // match, any and resource registrations are not read: whatever controller they name gets an unprovable mutation entry.
                    foreach ((new NodeFinder)->findInstanceOf($arguments, Node\Expr\ClassConstFetch::class) as $named) {
                        if ($named->class instanceof Node\Name) {
                            $routes[] = ['verbs' => self::MUTATING, 'page' => null, 'uri' => null, 'class' => $named->class->toString(), 'method' => '*', 'guest' => false, 'name' => null];
                        }
                    }

                    // Their generated names are not read either: one unreadable name means no route name provably resolves.
                    $routes[] = ['verbs' => [], 'page' => null, 'uri' => null, 'class' => null, 'method' => null, 'guest' => false, 'name' => null];

                    continue;
                }
                $uri = ($arguments[0]->value ?? null) instanceof Node\Scalar\String_ ? $arguments[0]->value->value : null;
                // Route::inertia('<uri>', '<literal page>') renders that page directly; a non-literal page name proves nothing.
                $rendered = $verb === 'inertia' && ($arguments[1]->value ?? null) instanceof Node\Scalar\String_ && $arguments[1]->name === null ? $arguments[1]->value->value : null;
                [$class, $method] = $verb === 'inertia' ? [null, null] : $this->action($arguments[1]->value ?? null, $call);
                $guest = ! $includedInGroup;
                // Builder calls before the verb (Route::middleware(...)->post) and after it (->post(...)->middleware(...)).
                $after = [];
                for ($child = $call, $parent = $call->getAttribute('parent'); $parent instanceof Node\Expr\MethodCall && $parent->var === $child; $child = $parent, $parent = $parent->getAttribute('parent')) {
                    $after[] = $parent;
                }
                $prefix = '';
                foreach ([...$chain, ...$after] as $link) {
                    $guest = $guest && $this->guestLink($link, $prefix);
                }
                foreach ($this->groups($call) as $group) {
                    $guest = $guest && $group !== null && $this->guestBuilder($group, $prefix);
                    if ($group === null) {
                        $uri = null;
                    }
                }
                if ($prefix === null) {
                    $uri = null;
                }
                $routes[] = ['verbs' => [$verb === 'inertia' ? 'get' : $verb], 'page' => $rendered, 'uri' => $uri === null ? null : trim(($prefix ?? '').'/'.trim($uri, '/'), '/'), 'class' => $class, 'method' => $method, 'guest' => $guest, 'name' => $this->routeName($call, $chain, $after)];
            }
        }

        return $this->routes = $routes;
    }

    /**
     * The full name a route registers: every enclosing group's `name('<prefix>')` / `as('<prefix>')`, outermost first, then the route's own
     * builder calls in order (Laravel appends each). '' for an unnamed route; null when any part is not a literal string, or a group is
     * `Route::group([...])`, whose `as` key this reader does not read.
     *
     * @param  list<Node\Expr>  $chain  the builder calls before the verb, innermost first
     * @param  list<Node\Expr>  $after  the calls chained after the verb, in order
     */
    private function routeName(Node $call, array $chain, array $after): ?string
    {
        $links = [];
        foreach (array_reverse($this->groups($call)) as $group) {
            if ($group === null) {
                return null;
            }
            $builder = [];
            for (; $group instanceof Node\Expr\MethodCall || $group instanceof Node\Expr\StaticCall; $group = $group instanceof Node\Expr\MethodCall ? $group->var : null) {
                $builder[] = $group;
            }
            $links = [...$links, ...array_reverse($builder)];
        }
        $name = '';
        foreach ([...$links, ...array_reverse($chain), ...$after] as $link) {
            if (! ($link instanceof Node\Expr\MethodCall || $link instanceof Node\Expr\StaticCall) || ! $link->name instanceof Node\Identifier || ! in_array(strtolower($link->name->toString()), ['name', 'as'], true)) {
                continue;
            }
            $argument = $link->getArgs()[0] ?? null;
            if ($link->isFirstClassCallable() || count($link->getArgs()) !== 1 || ! $argument->value instanceof Node\Scalar\String_) {
                return null;
            }
            $name .= $argument->value->value;
        }

        return $name;
    }

    /** @return array{?string, ?string} the controller class and method of a route action */
    private function action(?Node\Expr $action, Node $call): array
    {
        if ($action instanceof Node\Expr\Array_ && count($action->items) === 2) {
            $class = $action->items[0]->value ?? null;
            $method = $action->items[1]->value ?? null;
            if ($class instanceof Node\Expr\ClassConstFetch && $class->class instanceof Node\Name && $method instanceof Node\Scalar\String_) {
                return [$class->class->toString(), $method->value];
            }
        }
        if ($action instanceof Node\Expr\ClassConstFetch && $action->class instanceof Node\Name && $action->name instanceof Node\Identifier && strtolower($action->name->toString()) === 'class') {
            return [$action->class->toString(), '__invoke'];
        }
        if ($action instanceof Node\Scalar\String_ && ! str_contains($action->value, '@')) {
            $controller = $this->groupController($call);
            if ($controller !== null) {
                return [$controller, $action->value];
            }
        }

        return [null, null];
    }

    /**
     * The builder of every `->group(...)` enclosing the node, innermost first; null for a group this reader cannot read (`Route::group([...])`).
     *
     * @return list<?Node\Expr>
     */
    private function groups(Node $node): array
    {
        $groups = [];
        for ($parent = $node->getAttribute('parent'); $parent instanceof Node; $parent = $parent->getAttribute('parent')) {
            if (($parent instanceof Node\Expr\MethodCall || $parent instanceof Node\Expr\StaticCall) && $parent->name instanceof Node\Identifier && strtolower($parent->name->toString()) === 'group') {
                $groups[] = $parent instanceof Node\Expr\MethodCall ? $parent->var : null;
            }
        }

        return $groups;
    }

    private function groupController(Node $node): ?string
    {
        foreach ($this->groups($node) as $builder) {
            while ($builder instanceof Node\Expr\MethodCall || $builder instanceof Node\Expr\StaticCall) {
                if ($builder->name instanceof Node\Identifier && strtolower($builder->name->toString()) === 'controller') {
                    $controller = $builder->getArgs()[0]->value ?? null;
                    if ($controller instanceof Node\Expr\ClassConstFetch && $controller->class instanceof Node\Name) {
                        return $controller->class->toString();
                    }
                }
                $builder = $builder instanceof Node\Expr\MethodCall ? $builder->var : null;
            }
        }

        return null;
    }

    private function guestBuilder(Node\Expr $builder, ?string &$prefix): bool
    {
        $guest = true;
        while ($builder instanceof Node\Expr\MethodCall || $builder instanceof Node\Expr\StaticCall) {
            $guest = $guest && $this->guestLink($builder, $prefix);
            $builder = $builder instanceof Node\Expr\MethodCall ? $builder->var : null;
        }

        return $guest;
    }

    /** Whether one builder call keeps the route provably guest; a literal prefix is prepended, any other makes the URI unknown. */
    private function guestLink(Node\Expr $link, ?string &$prefix): bool
    {
        if ((! $link instanceof Node\Expr\MethodCall && ! $link instanceof Node\Expr\StaticCall) || ! $link->name instanceof Node\Identifier) {
            return true;
        }
        $name = strtolower($link->name->toString());
        $argument = $link->getArgs()[0]->value ?? null;
        if ($name === 'prefix') {
            $prefix = $argument instanceof Node\Scalar\String_ && $prefix !== null ? trim(trim($argument->value, '/').'/'.$prefix, '/') : null;

            return true;
        }
        if ($name !== 'middleware') {
            // can() authorizes a signed-in user.
            return $name !== 'can';
        }
        $items = $argument instanceof Node\Expr\Array_ ? array_map(fn ($item): ?Node\Expr => $item?->value, $argument->items) : [$argument];
        foreach ($items as $item) {
            if (! $item instanceof Node\Scalar\String_ || ! in_array(explode(':', $item->value)[0], self::GUEST_MIDDLEWARE, true)) {
                return false;
            }
        }

        return true;
    }
}
