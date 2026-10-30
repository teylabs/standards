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
 *  1. Every route that registers Controller@action is found in the route roots, is a literal mutating route (`Route::post|put|patch|delete`,
 *     `match`, `any`, or a readable `resource`/`apiResource`, see routes()) with a literal URI, and every middleware on it and its enclosing
 *     groups (builder or `Route::group([...])` array, groupAttributes()) is a literal from GUEST_MIDDLEWARE. Any other middleware, a non-literal
 *     one, a non-literal prefix, an unreadable group, a mutating route whose controller can't be qualified, or an include/require inside a
 *     group anywhere in the route roots makes it unprovable. A controller that declares `middleware()` or calls `$this->middleware()` is never guest.
 *  2. For each such route, exactly one GET route with the same full literal URI exists: a controller action (the same controller, or the
 *     one class whose fully qualified name, resolved as Laravel's Router does (action()), is declared exactly once under the controller roots
 *     or, failing that, in the file composer's maps give it), or `Route::inertia('<uri>', '<literal page>')`.
 *     A literal named-route redirect (`to_route('<name>')`, `redirect()->route('<name>')`, `Redirect::route('<name>')`, one argument only;
 *     redirectFlash()) lands instead on the one route registered under that full name (group `name()`/`as()` prefixes plus the route's own,
 *     routeName()): it must be a guest GET (or GET and HEAD) with a literal URI and no parameters, and no registration in the route roots may
 *     carry an unreadable name (routes(): a non-literal name, an unreadable group or resource, a macro or other unknown Route call).
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

    /** @var ?list<array{verbs: list<string>, page: ?string, uri: ?string, class: ?string, method: ?string, guest: bool, name: ?string, unresolved?: bool}> */
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
        // A mutating route whose controller this reader could not qualify might be this action's: nothing is provable then.
        if ($mutations === [] || array_filter($routes, fn (array $route): bool => ($route['unresolved'] ?? false) && array_intersect($route['verbs'], self::MUTATING) !== []) !== []) {
            return false;
        }
        foreach ($mutations as $mutation) {
            if (! $mutation['guest'] || $mutation['uri'] === null) {
                return false;
            }
            if ($route !== null) {
                // The named target: exactly one registration carries the name, none carries a name this reader cannot read, and it is a guest
                // GET (or GET and HEAD) with a literal, parameterless URI.
                $pages = array_values(array_filter($routes, fn (array $candidate): bool => $candidate['name'] === $route));
                if (in_array(null, array_column($routes, 'name'), true) || count($pages) !== 1 || ! in_array('get', $pages[0]['verbs'], true) || array_diff($pages[0]['verbs'], ['get', 'head']) !== [] || ! $pages[0]['guest'] || $pages[0]['uri'] === null || str_contains($pages[0]['uri'], '{')) {
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
    public function environmentKeepsGuest(): bool
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
            if (! $this->safeMiddleware($argument->value)) {
                return false;
            }
        }

        return true;
    }

    /** A literal Class::class (or a list of them) on SAFE_MIDDLEWARE or declared in Configuration::$guestSafeMiddleware. */
    public function safeMiddleware(?Node\Expr $value): bool
    {
        if ($value instanceof Node\Expr\Array_) {
            foreach ($value->items as $item) {
                if ($item === null || $item->key !== null && ! $item->key instanceof Node\Scalar\String_ || ! $this->safeMiddleware($item->value)) {
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
        if ($this->declaredSafe($class)) {
            return true;
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
        $paths = $this->providerPaths();
        if ($paths === null) {
            return false;
        }
        foreach ($paths as $path) {
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
                if (($route && $name === 'middleware') && ! $this->safeMiddleware($node->getArgs()[0]->value ?? null)) {
                    return false;
                }
                if (in_array($name, ['pushmiddlewaretogroup', 'prependmiddlewaretogroup', 'appendmiddlewaretogroup', 'middlewaregroup'], true) && ! $this->safeMiddleware($node->getArgs()[1]->value ?? null)) {
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

    /** @return ?list<string> the app's provider files: everything under app/Providers plus the declared classes bootstrap/providers.php lists; null when bootstrap/providers.php does not parse */
    public function providerPaths(): ?array
    {
        $paths = array_keys($this->source->files(['app/Providers']));
        if (is_file($this->config->base.'/bootstrap/providers.php')) {
            try {
                $listed = (new NodeFinder)->findInstanceOf($this->source->nodes('bootstrap/providers.php'), Node\Expr\ClassConstFetch::class);
            } catch (\PhpParser\Error) {
                return null;
            }
            $declared = $this->source->declared();
            foreach ($listed as $constant) {
                if ($constant->class instanceof Node\Name && isset($declared[$constant->class->toString()])) {
                    $paths[] = $declared[$constant->class->toString()];
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /** Route aliases that sign someone in or authorize a signed-in user: never declarable, with or without parameters. */
    public const AUTH_ALIASES = ['auth', 'auth.basic', 'auth.session', 'verified', 'password.confirm', 'can'];

    /** Laravel's own auth middleware: never declarable, nor an alias bootstrap/app.php maps to one. */
    public const AUTH_CLASSES = [
        'Illuminate\\Auth\\Middleware\\Authenticate',
        'Illuminate\\Auth\\Middleware\\AuthenticateWithBasicAuth',
        'Illuminate\\Session\\Middleware\\AuthenticateSession',
        'Illuminate\\Auth\\Middleware\\EnsureEmailIsVerified',
        'Illuminate\\Auth\\Middleware\\RequirePassword',
        'Illuminate\\Auth\\Middleware\\Authorize',
    ];

    /** @var ?array{safe: list<string>, errors: list<array{target: string, check: string, hint: string}>} */
    private ?array $declared = null;

    /** Whether a middleware class or alias string is one a valid Configuration::$guestSafeMiddleware entry declares. */
    private function declaredSafe(string $middleware): bool
    {
        $middleware = ltrim($middleware, '\\');
        foreach ($this->declarations()['safe'] as $entry) {
            if ($middleware === $entry || (str_ends_with($entry, ':*') && str_starts_with($middleware, substr($entry, 0, -1)) && strlen($middleware) > strlen($entry) - 1)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The invalid Configuration::$guestSafeMiddleware entries, as `toast` findings. Each declares nothing.
     *
     * @return list<array{target: string, check: string, hint: string}>
     */
    public function configurationErrors(): array
    {
        return $this->declarations()['errors'];
    }

    /** @return array{safe: list<string>, errors: list<array{target: string, check: string, hint: string}>} */
    private function declarations(): array
    {
        if ($this->declared !== null) {
            return $this->declared;
        }
        $safe = [];
        $errors = [];
        $hint = 'Configuration::$guestSafeMiddleware declares middleware that signs no one in: [\'middleware\' => Class::class or \'alias:params\', \'reason\' => \'why it signs no one in\']. Auth middleware can never be declared.';
        $aliases = null;
        foreach (array_values($this->config->guestSafeMiddleware) as $index => $entry) {
            $fail = function (string $why) use (&$errors, $index, $hint): void {
                $errors[] = ['target' => 'tests/Arch/configuration.php', 'check' => "guestSafeMiddleware entry {$index} {$why}", 'hint' => $hint];
            };
            $middleware = is_array($entry) ? ($entry['middleware'] ?? null) : null;
            $reason = is_array($entry) ? ($entry['reason'] ?? null) : null;
            if (! is_array($entry) || array_diff(array_keys($entry), ['middleware', 'reason']) !== []) {
                $fail('must be an array with only middleware and reason');
            } elseif (! is_string($middleware) || trim($middleware) === '' || trim($middleware) !== $middleware) {
                $fail('needs a non-empty string middleware');
            } elseif (! is_string($reason) || trim($reason) === '') {
                $fail("({$middleware}) needs a non-empty reason");
            } elseif (str_contains($middleware, '\\')) {
                $class = ltrim($middleware, '\\');
                $why = $this->authClass($class);
                $why === null ? $safe[] = $class : $fail("({$middleware}) {$why}");
            } elseif (preg_match('~\A[A-Za-z][\w.-]*(?::.+)?\z~', $middleware) !== 1 || str_contains(explode(':', $middleware)[0], '*')) {
                $fail("({$middleware}) is neither a class nor an alias[:parameters] string");
            } elseif (in_array(explode(':', $middleware)[0], self::AUTH_ALIASES, true)) {
                $fail("({$middleware}) names an auth middleware");
            } else {
                $aliases ??= $this->bootstrapAliases();
                $target = $aliases[explode(':', $middleware)[0]] ?? null;
                $why = $target === null ? null : $this->authClass($target);
                $why === null ? $safe[] = $middleware : $fail("({$middleware}) is an alias of {$target}, which {$why}");
            }
        }

        return $this->declared = ['safe' => $safe, 'errors' => $errors];
    }

    /** @return array<string, string> alias => the literal class bootstrap/app.php's alias() calls (or an app provider's aliasMiddleware()) map it to; '(non-literal)' when it is not a literal class, which then cannot be read */
    private function bootstrapAliases(): array
    {
        $aliases = [];
        try {
            $nodes = is_file($this->config->base.'/bootstrap/app.php') ? $this->source->nodes('bootstrap/app.php') : [];
        } catch (\PhpParser\Error) {
            return [];
        }
        foreach ((new NodeFinder)->find($nodes, fn (Node $node): bool => $node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier && strtolower($node->name->toString()) === 'alias') as $call) {
            /** @var Node\Expr\MethodCall $call */
            $map = $call->getArgs()[0]->value ?? null;
            foreach ($map instanceof Node\Expr\Array_ ? $map->items : [] as $item) {
                if ($item?->key instanceof Node\Scalar\String_) {
                    $value = $item->value;
                    $aliases[$item->key->value] = $value instanceof Node\Expr\ClassConstFetch && $value->class instanceof Node\Name ? $value->class->toString() : ($value instanceof Node\Scalar\String_ ? ltrim($value->value, '\\') : '(non-literal)');
                }
            }
        }

        // A provider's $router->aliasMiddleware('<alias>', X::class) maps it as well; an unreadable one maps it to an unknown class.
        foreach (array_keys($this->source->files(['app/Providers'])) as $path) {
            try {
                $provider = $this->source->nodes($path);
            } catch (\PhpParser\Error) {
                continue;
            }
            foreach ((new NodeFinder)->find($provider, fn (Node $node): bool => ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall) && $node->name instanceof Node\Identifier && strtolower($node->name->toString()) === 'aliasmiddleware') as $call) {
                /** @var Node\Expr\MethodCall|Node\Expr\StaticCall $call */
                $alias = $call->getArgs()[0]->value ?? null;
                $value = $call->getArgs()[1]->value ?? null;
                if ($alias instanceof Node\Scalar\String_) {
                    $aliases[$alias->value] = $value instanceof Node\Expr\ClassConstFetch && $value->class instanceof Node\Name ? $value->class->toString() : ($value instanceof Node\Scalar\String_ ? ltrim($value->value, '\\') : '(non-literal)');
                }
            }
        }

        return $aliases;
    }

    /**
     * Why a middleware class can't be declared guest-safe, or null when it can: it is one of AUTH_CLASSES or extends one (app or vendor
     * parents, read statically from the source roots or composer's classmap and PSR-4 maps), it names Illuminate\Auth\Middleware\Authenticate
     * anywhere in its body, or it or a parent cannot be found or parsed.
     */
    private function authClass(?string $class): ?string
    {
        for ($depth = 0; $class !== null; $depth++) {
            if (in_array($class, self::AUTH_CLASSES, true)) {
                return $depth === 0 ? 'is auth middleware' : "extends {$class}";
            }
            if ($depth >= 20) {
                return 'has an ancestry too deep to read';
            }
            $declaration = $this->classDeclaration($class);
            if ($declaration === null) {
                return "cannot be read ({$class} is not found in the source roots or composer's autoload maps)";
            }
            $named = (new NodeFinder)->find($declaration->stmts, fn (Node $node): bool => $node instanceof Node\Name && in_array($node->toString(), self::AUTH_CLASSES, true));
            if ($named !== []) {
                return "names {$named[0]->toString()} in {$class}";
            }
            $class = $declaration->extends?->toString();
        }

        return null;
    }

    private function classDeclaration(string $class): ?Node\Stmt\Class_
    {
        $declared = $this->source->declared();
        try {
            if (isset($declared[$class])) {
                $nodes = $this->source->nodes($declared[$class]);
            } else {
                $file = $this->autoloaded($class);
                if ($file === null) {
                    return null;
                }
                $nodes = (new \PhpParser\NodeTraverser(new \PhpParser\NodeVisitor\NameResolver))->traverse((new \PhpParser\ParserFactory)->createForNewestSupportedVersion()->parse((string) file_get_contents($file)) ?? []);
            }
        } catch (\PhpParser\Error) {
            return null;
        }
        $matches = (new NodeFinder)->find($nodes, fn (Node $node): bool => $node instanceof Node\Stmt\Class_ && isset($node->namespacedName) && $node->namespacedName->toString() === $class);

        return count($matches) === 1 && $matches[0] instanceof Node\Stmt\Class_ ? $matches[0] : null;
    }

    /** @var array{classmap: array<string, string>, psr4: array<string, list<string>>}|null|false */
    private array|null|false $autoload = null;

    /** The file composer's static maps (vendor/composer autoload_classmap.php, then autoload_psr4.php) give the class, as CruddyGuard reads them; nothing is loaded. */
    private function autoloaded(string $class): ?string
    {
        if ($this->autoload === null) {
            $this->autoload = false;
            $vendor = $this->config->base.'/vendor/composer';
            if (is_file($vendor.'/autoload_classmap.php') || is_file($vendor.'/autoload_psr4.php')) {
                $read = static fn (string $file): array => is_file($file) && is_array($map = (static fn () => require $file)()) ? $map : [];
                $this->autoload = ['classmap' => $read($vendor.'/autoload_classmap.php'), 'psr4' => $read($vendor.'/autoload_psr4.php')];
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

    /**
     * The one class declaration whose fully qualified name is `$name`: declared exactly once under the controller roots, or, when none
     * declares it there, exactly once in the file composer's classmap or PSR-4 map gives it. Null when it is declared more than once, nowhere,
     * or only in a file that does not parse. A short name that no route file's imports or namespace qualified never matches.
     */
    private function controllerClass(string $name): ?Node\Stmt\Class_
    {
        if (array_key_exists($name, $this->classes)) {
            return $this->classes[$name];
        }
        $declares = fn (array $nodes): array => (new NodeFinder)->find($nodes, fn (Node $node): bool => $node instanceof Node\Stmt\Class_ && isset($node->namespacedName) && $node->namespacedName->toString() === $name);
        $found = [];
        foreach ($this->source->files($this->config->controllerRoots) as $path => $text) {
            try {
                $found = [...$found, ...$declares($this->source->nodes($path))];
            } catch (\PhpParser\Error) {
                continue;
            }
        }
        if ($found === [] && ($file = $this->autoloaded($name)) !== null) {
            $base = rtrim((string) realpath($this->config->base), '/').'/';
            try {
                $nodes = str_starts_with($file, $base)
                    ? $this->source->nodes(substr($file, strlen($base)))
                    : (new \PhpParser\NodeTraverser(new \PhpParser\NodeVisitor\NameResolver, new \PhpParser\NodeVisitor\ParentConnectingVisitor))->traverse((new \PhpParser\ParserFactory)->createForNewestSupportedVersion()->parse((string) file_get_contents($file)) ?? []);
                $found = $declares($nodes);
            } catch (\PhpParser\Error) {
                $found = [];
            }
        }

        return $this->classes[$name] = count($found) === 1 && $found[0] instanceof Node\Stmt\Class_ ? $found[0] : null;
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
    public function rendersFormStatus(string $page, array $keys): bool
    {
        $page = $this->pageFile($page);
        if ($page === null) {
            return false;
        }
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

    /** The base-relative path of the one `<page>.vue` under the page roots, or null when there is none, more than one, or the name climbs out. */
    public function pageFile(string $page): ?string
    {
        if (str_contains($page, '..') || $page === '') {
            return null;
        }
        $files = [];
        foreach ($this->config->pageRoots as $root) {
            foreach (glob($this->config->base.'/'.$root, GLOB_ONLYDIR) ?: [] as $directory) {
                if (is_file($directory.'/'.$page.'.vue')) {
                    $files[] = $directory.'/'.$page.'.vue';
                }
            }
        }
        $files = array_values(array_unique($files));

        return count($files) === 1 ? substr($files[0], strlen($this->config->base) + 1) : null;
    }

    /**
     * Whether the page, or a `.vue` file it imports (LEVELS, as rendersFormStatus()), satisfies the predicate.
     *
     * @param  callable(string, string): bool  $predicate  given each file's path and source
     */
    public function pageTreeReaches(string $page, callable $predicate): bool
    {
        $follow = fn (array $import, string $from): ?string => self::component($this->config->base, $import, $from);

        return VueImports::reaches($this->config->base, $page, self::LEVELS, $predicate, $follow);
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

    /** Route facade calls that register routes. */
    private const REGISTERS = ['get', 'post', 'put', 'patch', 'delete', 'options', 'any', 'match', 'inertia', 'view', 'redirect', 'permanentredirect', 'resource', 'apiresource', 'resources', 'apiresources', 'singleton', 'singletons', 'apisingleton', 'apisingletons', 'fallback'];

    /** Builder calls a route, a group or a resource registration may chain; they register nothing. */
    private const BUILDERS = ['as', 'can', 'controller', 'domain', 'metadata', 'middleware', 'missing', 'name', 'namespace', 'prefix', 'scopebindings', 'where', 'withoutmiddleware', 'withoutscopedbindings', 'group', 'wherenumber', 'wherealpha', 'wherealphanumeric', 'whereuuid', 'whereulid', 'wherein', 'only', 'except', 'names', 'parameters', 'parameter', 'shallow', 'scoped', 'middlewarefor', 'withoutmiddlewarefor', 'withtrashed', 'defaults', 'block', 'withoutblocking', 'creatable', 'destroyable'];

    /** Route facade calls that register no route. Any other call on the facade (a macro, say) may register a name this reader cannot read. */
    private const HELPERS = ['pattern', 'patterns', 'bind', 'model', 'macro', 'mixin', 'hasmacro', 'current', 'currentroutename', 'currentrouteaction', 'currentrouteuses', 'currentrouteis', 'currentroutenamed', 'has', 'is', 'getroutes', 'getcurrentroute', 'getcurrentrequest', 'substitutebindings', 'substituteimplicitbindings'];

    /** Calls that change how resource names, verbs or parameters are generated; seen anywhere in the route roots or app providers, no resource is read. */
    private const RESOURCE_TWEAKS = ['resourceverbs', 'resourceparameters', 'singularresourceparameters', 'singularparameters', 'setparameters', 'verbs'];

    /** Laravel's resource actions in ResourceRegistrar::$resourceDefaults order: [verbs, URI suffix after the resource URI, whether it takes the {wildcard}]. */
    private const RESOURCE_ACTIONS = [
        'index' => [['get'], '', false],
        'create' => [['get'], '/create', false],
        'store' => [['post'], '', false],
        'show' => [['get'], '', true],
        'edit' => [['get'], '/edit', true],
        'update' => [['put', 'patch'], '', true],
        'destroy' => [['delete'], '', true],
    ];

    /** Router::$verbs, lowercased: what `Route::any()` and `Route::redirect()` register. */
    private const ALL_VERBS = ['get', 'head', 'post', 'put', 'patch', 'delete', 'options', 'query'];

    /** @var ?bool */
    private ?bool $resourcesTweaked = null;

    /**
     * Every route registration in the route roots that this reader can see: `Route::get|post|put|patch|delete|options|any|match|inertia|view|
     * redirect|permanentRedirect(...)` and `Route::resource|apiResource(...)`, with their verbs, full literal URIs, fully qualified controllers,
     * guest status and full names. Anything it cannot read (a `resources`/`singleton`/`fallback` registration, a non-literal resource argument,
     * a macro or other unknown facade call) adds an entry with a null name, which blocks every named-route redirect, and an unprovable
     * mutation entry for each class it names.
     *
     * @return list<array{verbs: list<string>, page: ?string, uri: ?string, class: ?string, method: ?string, guest: bool, name: ?string, unresolved?: bool}>
     */
    public function routes(): array
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
        $unreadable = ['verbs' => [], 'page' => null, 'uri' => null, 'class' => null, 'method' => null, 'guest' => false, 'name' => null];
        foreach ($files as $path => $text) {
            foreach ($finder->find($this->source->nodes($path), fn (Node $node): bool => $node instanceof Node\Expr\StaticCall && self::routeFacade($node)) as $root) {
                $previous = null;
                // A macro or any other facade call this reader does not know may register a route under any name.
                for ($link = $root; $link instanceof Node\Expr\StaticCall || ($link instanceof Node\Expr\MethodCall && $link->var === $previous); $previous = $link, $link = $link->getAttribute('parent')) {
                    $name = $link->name instanceof Node\Identifier ? strtolower($link->name->toString()) : null;
                    if ($name === null || ! in_array($name, [...self::REGISTERS, ...self::BUILDERS, ...self::HELPERS], true)) {
                        $routes[] = $unreadable;

                        break;
                    }
                }
            }
            foreach ($finder->find($this->source->nodes($path), fn (Node $node): bool => ($node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\MethodCall) && $node->name instanceof Node\Identifier) as $call) {
                /** @var Node\Expr\StaticCall|Node\Expr\MethodCall $call */
                $verb = strtolower($call->name->toString());
                if (! in_array($verb, self::REGISTERS, true) || $call->isFirstClassCallable()) {
                    continue;
                }
                $chain = [];
                $root = $call;
                while ($root instanceof Node\Expr\MethodCall) {
                    $root = $root->var;
                    $chain[] = $root;
                }
                if (! $root instanceof Node\Expr\StaticCall || ! self::routeFacade($root)) {
                    continue;
                }
                // Builder calls after the verb (->post(...)->middleware(...)).
                $after = [];
                for ($child = $call, $parent = $call->getAttribute('parent'); $parent instanceof Node\Expr\MethodCall && $parent->var === $child; $child = $parent, $parent = $parent->getAttribute('parent')) {
                    $after[] = $parent;
                }
                $guest = ! $includedInGroup;
                $prefix = '';
                foreach ([...$chain, ...$after] as $link) {
                    $guest = $this->guestLink($link, $prefix) && $guest;
                }
                foreach ($this->groups($call) as $group) {
                    $guest = $group !== null && $this->guestBuilder($group, $prefix) && $guest;
                    if ($group === null) {
                        $prefix = null;
                    }
                }
                $read = in_array($verb, ['resource', 'apiresource'], true)
                    ? $this->resourceRoutes($call, $verb === 'apiresource', $chain, $after, $guest, $prefix)
                    : $this->route($call, $verb, $chain, $after, $guest, $prefix);
                if ($read === null) {
                    // An unreadable registration: whatever controller it names gets an unprovable mutation entry, and its name blocks every
                    // named-route redirect.
                    foreach ((new NodeFinder)->findInstanceOf($call->getArgs(), Node\Expr\ClassConstFetch::class) as $named) {
                        if ($named->class instanceof Node\Name) {
                            $routes[] = ['verbs' => self::MUTATING, 'page' => null, 'uri' => null, 'class' => $named->class->toString(), 'method' => '*', 'guest' => false, 'name' => null];
                        }
                    }
                    $routes[] = $unreadable;

                    continue;
                }
                $routes = [...$routes, ...$read];
            }
        }

        return $this->routes = $routes;
    }

    private static function routeFacade(Node\Expr\StaticCall $call): bool
    {
        return $call->class instanceof Node\Name && $call->class->toString() === 'Illuminate\Support\Facades\Route';
    }

    /**
     * One route from a single-route registration, or null when it cannot be read: `match` with non-literal verbs, or a `resources`,
     * `singleton`, `fallback` or similar registration, whose routes this reader does not generate.
     *
     * @param  list<Node\Expr>  $chain
     * @param  list<Node\Expr>  $after
     * @return ?list<array{verbs: list<string>, page: ?string, uri: ?string, class: ?string, method: ?string, guest: bool, name: ?string, unresolved?: bool}>
     */
    private function route(Node\Expr\StaticCall|Node\Expr\MethodCall $call, string $verb, array $chain, array $after, bool $guest, ?string $prefix): ?array
    {
        $arguments = $call->getArgs();
        if ($verb === 'match') {
            $methods = $arguments[0]->value ?? null;
            $methods = $methods instanceof Node\Expr\Array_ ? array_map(fn (?Node\ArrayItem $item): ?Node\Expr => $item?->unpack === false && $item->key === null ? $item->value : null, $methods->items) : [$methods];
            $verbs = [];
            foreach ($methods as $method) {
                if (! $method instanceof Node\Scalar\String_ || ! in_array(strtolower($method->value), self::ALL_VERBS, true)) {
                    return null;
                }
                $verbs[] = strtolower($method->value);
            }
            $verbs = array_values(array_unique($verbs));
            if ($verbs === []) {
                return null;
            }
            $arguments = array_slice($arguments, 1);
        } elseif (in_array($verb, ['any', 'redirect', 'permanentredirect'], true)) {
            $verbs = self::ALL_VERBS;
        } elseif ($verb === 'view') {
            $verbs = ['get', 'head'];
        } elseif (in_array($verb, ['get', 'inertia', 'options', ...self::MUTATING], true)) {
            $verbs = [$verb === 'inertia' ? 'get' : $verb];
        } else {
            return null;
        }
        $uri = ($arguments[0]->value ?? null) instanceof Node\Scalar\String_ ? $arguments[0]->value->value : null;
        // Route::inertia('<uri>', '<literal page>') renders that page directly; a non-literal page name proves nothing.
        $rendered = $verb === 'inertia' && ($arguments[1]->value ?? null) instanceof Node\Scalar\String_ && $arguments[1]->name === null ? $arguments[1]->value->value : null;
        [$class, $method, $unresolved] = in_array($verb, ['inertia', 'view', 'redirect', 'permanentredirect'], true) ? [null, null, false] : $this->action($arguments[1]->value ?? null, $call, [...$chain, ...$after]);

        return [['verbs' => $verbs, 'page' => $rendered, 'uri' => $uri === null || $prefix === null ? null : trim($prefix.'/'.trim($uri, '/'), '/'), 'class' => $class, 'method' => $method, 'guest' => $guest, 'name' => $this->routeName($call, $chain, $after), 'unresolved' => $unresolved]];
    }

    /**
     * The routes `Route::resource('<name>', C::class)` or `Route::apiResource(...)` registers, as Laravel 13's ResourceRegistrar generates
     * them: index, create, store, show, edit, update and destroy (apiResource without create and edit), honouring literal `->only()`,
     * `->except()`, `->names()`, `->name()`, `->parameters()` and `->parameter()`, a nested `photos.comments` name and a prefixed
     * `admin/photos` name. Null when anything is not literal: a third options argument, `->shallow()`, `->scoped()`, `->middlewareFor()`
     * or another unknown chained call, a builder before the call other than `middleware()`, or an app that changes resource generation
     * (RESOURCE_TWEAKS, or any reference to ResourceRegistrar) anywhere in the route roots or providers.
     *
     * @param  list<Node\Expr>  $chain
     * @param  list<Node\Expr>  $after
     * @return ?list<array{verbs: list<string>, page: ?string, uri: ?string, class: ?string, method: ?string, guest: bool, name: ?string, unresolved?: bool}>
     */
    private function resourceRoutes(Node\Expr\StaticCall|Node\Expr\MethodCall $call, bool $api, array $chain, array $after, bool $guest, ?string $prefix): ?array
    {
        $arguments = $call->getArgs();
        $resource = $arguments[0]->value ?? null;
        $controller = $arguments[1]->value ?? null;
        if (count($arguments) !== 2 || $arguments[0]->unpack || $arguments[1]->unpack || $arguments[0]->name !== null || $arguments[1]->name !== null
            || ! $resource instanceof Node\Scalar\String_ || trim($resource->value, '/.') === '' || $this->resourcesTweaked()) {
            return null;
        }
        foreach ($chain as $link) {
            if (! ($link instanceof Node\Expr\MethodCall || $link instanceof Node\Expr\StaticCall) || ! $link->name instanceof Node\Identifier || strtolower($link->name->toString()) !== 'middleware') {
                return null;
            }
        }
        $only = $api ? ['index', 'show', 'store', 'update', 'destroy'] : null;
        $except = [];
        $names = [];
        $parameters = [];
        foreach ($after as $link) {
            $method = $link instanceof Node\Expr\MethodCall && $link->name instanceof Node\Identifier ? strtolower($link->name->toString()) : null;
            $values = $link instanceof Node\Expr\MethodCall && ! $link->isFirstClassCallable() ? $link->getArgs() : [];
            if (in_array($method, ['middleware', 'where', 'missing', 'withtrashed'], true)) {
                continue;
            }
            if (in_array($method, ['only', 'except'], true)) {
                $list = count($values) === 1 && $values[0]->value instanceof Node\Expr\Array_ ? self::literalList($values[0]->value) : self::literalArguments($values);
                if ($list === null) {
                    return null;
                }
                $method === 'only' ? $only = $list : $except = $list;
            } elseif ($method === 'names' && count($values) === 1 && $values[0]->value instanceof Node\Scalar\String_) {
                $names = $values[0]->value->value;
            } elseif ($method === 'names' && count($values) === 1 && $values[0]->value instanceof Node\Expr\Array_ && ($map = self::literalMap($values[0]->value)) !== null) {
                $names = $map;
            } elseif ($method === 'name' && is_array($names) && ($pair = self::literalArguments($values)) !== null && count($pair) === 2) {
                $names[$pair[0]] = $pair[1];
            } elseif ($method === 'parameters' && count($values) === 1 && $values[0]->value instanceof Node\Expr\Array_ && ($map = self::literalMap($values[0]->value)) !== null) {
                $parameters = $map;
            } elseif ($method === 'parameters' && count($values) === 1 && $values[0]->value instanceof Node\Scalar\String_ && $values[0]->value->value === 'singular') {
                $parameters = [];
            } elseif ($method === 'parameter' && ($pair = self::literalArguments($values)) !== null && count($pair) === 2) {
                $parameters[$pair[0]] = $pair[1];
            } else {
                return null;
            }
        }
        // The controller is a string `uses` (C@method): a group namespace applies, a group controller does not.
        $class = $controller instanceof Node\Expr\ClassConstFetch && $controller->class instanceof Node\Name && $controller->name instanceof Node\Identifier && strtolower($controller->name->toString()) === 'class'
            ? $controller->class->toString()
            : ($controller instanceof Node\Scalar\String_ ? $controller->value : null);
        $class = $class === null ? null : $this->qualify($class, $call, [...$chain, ...$after]);
        $group = $this->routeName($call, [], []);

        // A name with a slash registers under a prefix group of its leading segments (ResourceRegistrar::prefixedResource()).
        $segments = explode('/', $resource->value);
        $name = (string) array_pop($segments);
        $prefix = $prefix === null ? null : trim($prefix.'/'.implode('/', $segments), '/');
        $wildcard = fn (string $value): string => str_replace('-', '_', $parameters[$value] ?? \Illuminate\Support\Str::singular($value));
        $parts = explode('.', $name);
        $last = (string) array_pop($parts);
        $base = implode('/', array_map(fn (string $part): string => $part.'/{'.$wildcard($part).'}', $parts));
        $uri = trim($base.'/'.$last, '/');

        $routes = [];
        foreach (self::RESOURCE_ACTIONS as $action => [$verbs, $suffix, $parameter]) {
            if (($only !== null && ! in_array($action, $only, true)) || in_array($action, $except, true)) {
                continue;
            }
            $full = $uri.($parameter ? '/{'.$wildcard($last).'}' : '').$suffix;
            $routeName = is_array($names) && isset($names[$action]) ? $names[$action] : trim((is_string($names) ? $names : $name).'.'.$action, '.');
            $routes[] = ['verbs' => $verbs, 'page' => null, 'uri' => $prefix === null ? null : trim($prefix.'/'.$full, '/'), 'class' => $class, 'method' => $action, 'guest' => $guest, 'name' => $group === null ? null : $group.$routeName, 'unresolved' => $class === null];
        }

        return $routes;
    }

    /** Whether the route roots or app providers change how resources are generated, or reference ResourceRegistrar at all. */
    private function resourcesTweaked(): bool
    {
        if ($this->resourcesTweaked !== null) {
            return $this->resourcesTweaked;
        }
        $paths = [...array_keys($this->source->files($this->config->routeRoots)), ...($this->providerPaths() ?? [])];
        foreach (array_unique($paths) as $path) {
            try {
                $nodes = $this->source->nodes($path);
            } catch (\PhpParser\Error) {
                return $this->resourcesTweaked = true;
            }
            $found = (new NodeFinder)->findFirst($nodes, fn (Node $node): bool => ($node instanceof Node\Name && $node->toString() === 'Illuminate\Routing\ResourceRegistrar')
                || (($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall) && $node->name instanceof Node\Identifier && in_array(strtolower($node->name->toString()), self::RESOURCE_TWEAKS, true)));
            if ($found !== null) {
                return $this->resourcesTweaked = true;
            }
        }

        return $this->resourcesTweaked = false;
    }

    /** @return ?list<string> a list of literal strings, or null */
    private static function literalList(Node\Expr\Array_ $array): ?array
    {
        $values = [];
        foreach ($array->items as $item) {
            if ($item === null || $item->unpack || $item->key !== null || ! $item->value instanceof Node\Scalar\String_) {
                return null;
            }
            $values[] = $item->value->value;
        }

        return $values;
    }

    /** @param list<Node\Arg> $arguments @return ?list<string> the arguments when every one is a positional literal string */
    private static function literalArguments(array $arguments): ?array
    {
        $values = [];
        foreach ($arguments as $argument) {
            if ($argument->unpack || $argument->name !== null || ! $argument->value instanceof Node\Scalar\String_) {
                return null;
            }
            $values[] = $argument->value->value;
        }

        return $values === [] ? null : $values;
    }

    /** @return ?array<string, string> a literal string => literal string map, or null */
    private static function literalMap(Node\Expr\Array_ $array): ?array
    {
        $map = [];
        foreach ($array->items as $item) {
            if ($item === null || $item->unpack || ! $item->key instanceof Node\Scalar\String_ || ! $item->value instanceof Node\Scalar\String_) {
                return null;
            }
            $map[$item->key->value] = $item->value->value;
        }

        return $map;
    }

    /**
     * The full name a route registers: every enclosing group's `name('<prefix>')` / `as('<prefix>')` or `Route::group(['as' => '<prefix>'])`,
     * outermost first, then the route's own builder calls in order (Laravel appends each). '' for an unnamed route; null when any part is not
     * a literal string or a group cannot be read.
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
            if (self::arrayGroup($group)) {
                $attributes = self::groupAttributes($group);
                if ($attributes === null || (isset($attributes['as']) && ! $attributes['as'] instanceof Node\Scalar\String_)) {
                    return null;
                }
                if (isset($attributes['as'])) {
                    $links[] = $attributes['as'];
                }

                continue;
            }
            $builder = [];
            for (; $group instanceof Node\Expr\MethodCall || $group instanceof Node\Expr\StaticCall; $group = $group instanceof Node\Expr\MethodCall ? $group->var : null) {
                $builder[] = $group;
            }
            $links = [...$links, ...array_reverse($builder)];
        }
        $name = '';
        foreach ([...$links, ...array_reverse($chain), ...$after] as $link) {
            if ($link instanceof Node\Scalar\String_) {
                $name .= $link->value;

                continue;
            }
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

    /**
     * The fully qualified controller class and method of a route action, as Laravel's Router resolves it.
     *
     * `[X::class, 'm']` and `X::class` are already fully qualified by the route file's `use` imports, aliases and namespace (NameResolver);
     * Laravel never prefixes an array action with a group namespace. A string action, `X::class` (invokable), `'X@m'` or a method name in a
     * `Route::controller(C::class)->group()`, gets the innermost literal group `namespace` prepended unless it starts with `\\` or that
     * namespace, as Router::prependGroupNamespace() does; a non-literal group namespace, or one on the route's own builder chain, leaves the
     * class unknown. A `'X@m'` string is never resolved through `use` imports, because Laravel does not.
     *
     * @param  list<Node\Expr>  $links  the route's own builder calls, before and after the verb
     * @return array{?string, ?string, bool} the class, the method, and whether the action names a controller this reader could not qualify
     */
    private function action(?Node\Expr $action, Node $call, array $links = []): array
    {
        if ($action instanceof Node\Expr\Array_ && count($action->items) === 2) {
            $class = $action->items[0]->value ?? null;
            $method = $action->items[1]->value ?? null;
            if ($class instanceof Node\Expr\ClassConstFetch && $class->class instanceof Node\Name && $class->name instanceof Node\Identifier && strtolower($class->name->toString()) === 'class' && $method instanceof Node\Scalar\String_) {
                return [$class->class->toString(), $method->value, false];
            }

            return [null, null, true];
        }
        if ($action instanceof Node\Expr\ClassConstFetch && $action->class instanceof Node\Name && $action->name instanceof Node\Identifier && strtolower($action->name->toString()) === 'class') {
            [$class, $method] = [$action->class->toString(), '__invoke'];
        } elseif ($action instanceof Node\Scalar\String_ && str_contains($action->value, '@')) {
            [$class, $method] = explode('@', $action->value, 2);
        } elseif ($action instanceof Node\Scalar\String_ && $action->value !== '') {
            $controller = $this->groupController($call);
            if ($controller === false) {
                return [null, null, true];
            }
            [$class, $method] = $controller === null ? [$action->value, '__invoke'] : [$controller, $action->value];
        } else {
            return [null, null, $action !== null && ! $action instanceof Node\Expr\Closure && ! $action instanceof Node\Expr\ArrowFunction];
        }
        $class = $this->qualify($class, $call, $links);

        return $class === null || $method === '' ? [null, null, true] : [$class, $method, false];
    }

    /**
     * A string action's class with the enclosing groups' namespace prepended, as Router::prependGroupNamespace() does; null when that
     * namespace cannot be read, the route's own chain sets one, or the result is not a class name.
     *
     * @param  list<Node\Expr>  $links  the route's own builder calls
     */
    private function qualify(string $class, Node $call, array $links): ?string
    {
        foreach ($links as $link) {
            if (($link instanceof Node\Expr\MethodCall || $link instanceof Node\Expr\StaticCall) && $link->name instanceof Node\Identifier && strtolower($link->name->toString()) === 'namespace') {
                return null;
            }
        }
        $namespace = $this->groupNamespace($call);
        if ($namespace === false) {
            return null;
        }
        if ($namespace !== null && ! str_starts_with($class, '\\') && ! str_starts_with($class, $namespace)) {
            $class = $namespace.'\\'.$class;
        }
        $class = ltrim($class, '\\');

        return preg_match('~\A[A-Za-z_][\w]*(?:\\\\[A-Za-z_][\w]*)*\z~', $class) === 1 ? $class : null;
    }

    /**
     * The namespace the enclosing groups give a string action, merged outermost first as RouteGroup::formatNamespace() does: null for none,
     * false when any group's namespace is not a literal string or a group cannot be read.
     */
    private function groupNamespace(Node $node): string|null|false
    {
        $namespace = null;
        foreach (array_reverse($this->groups($node)) as $builder) {
            if ($builder === null) {
                return false;
            }
            $declared = null;
            if (self::arrayGroup($builder)) {
                $attributes = self::groupAttributes($builder);
                if ($attributes === null || (isset($attributes['namespace']) && ! $attributes['namespace'] instanceof Node\Scalar\String_)) {
                    return false;
                }
                $declared = isset($attributes['namespace']) ? $attributes['namespace']->value : null;
                $builder = null;
            }
            for (; $builder instanceof Node\Expr\MethodCall || $builder instanceof Node\Expr\StaticCall; $builder = $builder instanceof Node\Expr\MethodCall ? $builder->var : null) {
                if (! $builder->name instanceof Node\Identifier || strtolower($builder->name->toString()) !== 'namespace') {
                    continue;
                }
                $value = $builder->getArgs()[0]->value ?? null;
                if (! $value instanceof Node\Scalar\String_ || count($builder->getArgs()) !== 1) {
                    return false;
                }
                // The registrar keeps the last namespace() call; the chain is walked innermost (last) first.
                $declared ??= $value->value;
            }
            if ($declared !== null) {
                $namespace = $namespace !== null && ! str_starts_with($declared, '\\') ? trim($namespace, '\\').'\\'.trim($declared, '\\') : trim($declared, '\\');
            }
        }

        return $namespace === '' ? null : $namespace;
    }

    /**
     * The builder of every `->group(...)` enclosing the node, innermost first: the builder chain of `Route::...->group(fn)`, or the
     * `Route::group([...], fn)` call itself (arrayGroup(), read through groupAttributes()); null for any other group.
     *
     * @return list<?Node\Expr>
     */
    private function groups(Node $node): array
    {
        $groups = [];
        for ($parent = $node->getAttribute('parent'); $parent instanceof Node; $parent = $parent->getAttribute('parent')) {
            if (($parent instanceof Node\Expr\MethodCall || $parent instanceof Node\Expr\StaticCall) && $parent->name instanceof Node\Identifier && strtolower($parent->name->toString()) === 'group') {
                if ($parent instanceof Node\Expr\MethodCall) {
                    $groups[] = count($parent->getArgs()) === 1 ? $parent->var : null;
                } else {
                    $arguments = $parent->getArgs();
                    $groups[] = self::routeFacade($parent) && count($arguments) === 2 && ! $arguments[0]->unpack && $arguments[0]->name === null && $arguments[0]->value instanceof Node\Expr\Array_ ? $parent : null;
                }
            }
        }

        return $groups;
    }

    /** Whether a groups() entry is a `Route::group([...], fn)` call. */
    private static function arrayGroup(Node\Expr $group): bool
    {
        return $group instanceof Node\Expr\StaticCall && $group->name instanceof Node\Identifier && strtolower($group->name->toString()) === 'group';
    }

    /**
     * The attributes of a `Route::group([...], fn)` array, by key: only `as`, `prefix`, `middleware`, `namespace`, `controller` and `where`,
     * each once, with literal string keys. Null for any other key (`domain`, a spread, ...), which makes the group unreadable. The values are
     * checked where they are used.
     *
     * @return ?array<string, Node\Expr>
     */
    private static function groupAttributes(Node\Expr\StaticCall $group): ?array
    {
        $array = $group->getArgs()[0]->value ?? null;
        if (! $array instanceof Node\Expr\Array_) {
            return null;
        }
        $attributes = [];
        foreach ($array->items as $item) {
            if ($item === null || $item->unpack || ! $item->key instanceof Node\Scalar\String_ || ! in_array($item->key->value, ['as', 'prefix', 'middleware', 'namespace', 'controller', 'where'], true) || isset($attributes[$item->key->value])) {
                return null;
            }
            $attributes[$item->key->value] = $item->value;
        }

        return $attributes;
    }

    /** The innermost enclosing group's controller: a literal C::class, null when there is none, false when it cannot be read. */
    private function groupController(Node $node): string|null|false
    {
        foreach ($this->groups($node) as $builder) {
            if ($builder === null) {
                return false;
            }
            if (self::arrayGroup($builder)) {
                $attributes = self::groupAttributes($builder);
                if ($attributes === null) {
                    return false;
                }
                if (! isset($attributes['controller'])) {
                    continue;
                }
                $controller = $attributes['controller'];

                return $controller instanceof Node\Expr\ClassConstFetch && $controller->class instanceof Node\Name ? $controller->class->toString() : false;
            }
            while ($builder instanceof Node\Expr\MethodCall || $builder instanceof Node\Expr\StaticCall) {
                if ($builder->name instanceof Node\Identifier && strtolower($builder->name->toString()) === 'controller') {
                    $controller = $builder->getArgs()[0]->value ?? null;
                    return $controller instanceof Node\Expr\ClassConstFetch && $controller->class instanceof Node\Name ? $controller->class->toString() : false;
                }
                $builder = $builder instanceof Node\Expr\MethodCall ? $builder->var : null;
            }
        }

        return null;
    }

    private function guestBuilder(Node\Expr $builder, ?string &$prefix): bool
    {
        if (self::arrayGroup($builder)) {
            /** @var Node\Expr\StaticCall $builder */
            $attributes = self::groupAttributes($builder);
            if ($attributes === null) {
                $prefix = null;

                return false;
            }
            if (isset($attributes['prefix'])) {
                $prefix = $attributes['prefix'] instanceof Node\Scalar\String_ && $prefix !== null ? trim(trim($attributes['prefix']->value, '/').'/'.$prefix, '/') : null;
            }
            $middleware = $attributes['middleware'] ?? null;
            foreach ($middleware instanceof Node\Expr\Array_ ? array_map(fn (?Node\ArrayItem $item): ?Node\Expr => $item === null || $item->unpack || $item->key !== null ? null : $item->value, $middleware->items) : ($middleware === null ? [] : [$middleware]) as $item) {
                if (! $this->guestMiddleware($item)) {
                    return false;
                }
            }

            return true;
        }
        $guest = true;
        while ($builder instanceof Node\Expr\MethodCall || $builder instanceof Node\Expr\StaticCall) {
            $guest = $this->guestLink($builder, $prefix) && $guest;
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
            if (! $this->guestMiddleware($item)) {
                return false;
            }
        }

        return true;
    }

    /** Whether one route middleware entry keeps a route guest: a literal GUEST_MIDDLEWARE alias (any parameters), or a class or alias Configuration::$guestSafeMiddleware declares. */
    public function guestMiddleware(?Node\Expr $item): bool
    {
        $class = $item instanceof Node\Expr\ClassConstFetch && $item->class instanceof Node\Name && $item->name instanceof Node\Identifier && strtolower($item->name->toString()) === 'class' ? $item->class->toString() : null;
        if ($class !== null && $this->declaredSafe($class)) {
            return true;
        }

        return $item instanceof Node\Scalar\String_ && (in_array(explode(':', $item->value)[0], self::GUEST_MIDDLEWARE, true) || $this->declaredSafe($item->value));
    }
}
