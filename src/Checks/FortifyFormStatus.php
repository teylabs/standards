<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Tey\Standards\Configuration;
use Tey\Standards\Source;

/**
 * GuestFormStatus for Laravel Fortify's guest flows, whose routes are registered from vendor/ and so are never found in the route roots.
 *
 * An app response class bound to one of Fortify's success response contracts has its `toResponse()` read like an action (Toast's
 * returnFlashKeys(): top-level literal `Inertia::flash` keys plus the return's `->with` keys, on a `back()` or a literal named-route redirect).
 * Its flash replaces the toast only when all of these hold, or the toast is still required:
 *
 *  1. Binding. Exactly one registration of the contract across the app providers (GuestFormStatus::providerPaths()), and it is the literal
 *     `$this->app->singleton|bind|instance(<Contract>::class, <ThisClass>::class)`. Success contracts only (FLOWS); failure responses are not exempt.
 *  2. Config. config/fortify.php parses and returns a literal array: `prefix` a literal string (absent: ''), `middleware` a literal list
 *     (absent: ['web']), `guard` a literal string (absent: 'web'), `views` literal true (absent: true), `paths` absent or a literal string map,
 *     and `features` a literal list holding the flow's `Features::<feature>()` call. A missing file or any non-literal value is unprovable.
 *  3. Middleware. Every `fortify.middleware` entry is guest middleware (GuestFormStatus::guestMiddleware(): GUEST_MIDDLEWARE or declared in
 *     Configuration::$guestSafeMiddleware); Fortify adds `guest:<guard>` to the guest flows. bootstrap/app.php and the providers keep the
 *     route guest (GuestFormStatus::environmentKeepsGuest()). The email verification flow is `auth:<guard>`: signed in, never exempt.
 *  4. Routes. No app provider calls `Fortify::ignoreRoutes()`, `viewPrefix()` or `viewNamespace()`, or binds a Fortify view response contract;
 *     no route in the route roots has an unreadable URI or name, or the URI or name of a Fortify route the proof relies on.
 *  5. Page. `back()` lands on the flow's form page (FLOWS); a named redirect may name only `login` or `password.request`. That page is
 *     registered by exactly one app-provider `Fortify::<view>(fn () => Inertia::render('<literal>', ...))` (or a closure whose only statement
 *     returns that), and its tree reads every flashed key (GuestFormStatus::rendersFormStatus()). With no app registration, when the app adopted
 *     tey/kit's session-ui (KitShell::adopted()), kit's own registration is read from vendor/tey/kit's SessionUiProvider: it must pass
 *     `session('status')` into the page's view model, the only key flashed must be `status`, and the page's tree must render a bare kit
 *     `<FormStatus>` (no `message`, no `flash-key`), which shows the `status` prop.
 */
final class FortifyFormStatus
{
    private const FORTIFY = 'Laravel\\Fortify\\Fortify';

    private const FEATURES = 'Laravel\\Fortify\\Features';

    /** Fortify's success response contracts: [feature, guest?, POST route name, POST default path, back() page: route name, default path, view method]. */
    public const FLOWS = [
        'Laravel\\Fortify\\Contracts\\SuccessfulPasswordResetLinkRequestResponse' => ['resetPasswords', true, 'password.email', '/forgot-password', 'password.request', '/forgot-password', 'requestPasswordResetLinkView'],
        'Laravel\\Fortify\\Contracts\\PasswordResetResponse' => ['resetPasswords', true, 'password.update', '/reset-password', 'password.reset', '/reset-password/{token}', 'resetPasswordView'],
        'Laravel\\Fortify\\Contracts\\EmailVerificationNotificationSentResponse' => ['emailVerification', false, 'verification.send', '/email/verification-notification', 'verification.notice', '/email/verify', 'verifyEmailView'],
    ];

    /** Named-redirect targets: route name => [default path, view method, the feature it needs or null]. */
    private const NAMED = [
        'login' => ['/login', 'loginView', null],
        'password.request' => ['/forgot-password', 'requestPasswordResetLinkView', 'resetPasswords'],
    ];

    private const KIT_PROVIDER = 'vendor/tey/kit/src/Features/SessionUi/SessionUiProvider.php';

    /** @var array<string, mixed>|false|null */
    private array|false|null $settings = null;

    public function __construct(private readonly Configuration $config, private readonly Source $source, private readonly GuestFormStatus $guest) {}

    /**
     * @param  list<string>  $keys  the keys toResponse() flashes
     * @param  ?string  $route  the literal route name it redirects to; null for back()
     */
    public function proves(Node\Stmt\Class_ $class, string $method, array $keys, ?string $route): bool
    {
        if (strtolower($method) !== 'toresponse' || ! isset($class->namespacedName) || $keys === []) {
            return false;
        }
        $paths = $this->guest->providerPaths();
        if ($paths === null) {
            return false;
        }
        $contract = $this->boundContract($class->namespacedName->toString(), $paths);
        if ($contract === null) {
            return false;
        }
        [$feature, $guestFlow, $postName, $postPath, $pageName, $pagePath, $view] = self::FLOWS[$contract];
        // The verification notification is sent from behind auth:<guard>: a signed-in flow, never a guest form.
        if (! $guestFlow) {
            return false;
        }
        $settings = $this->settings();
        if ($settings === false || ! in_array($feature, $settings['features'], true) || ! $this->guest->environmentKeepsGuest() || ! $this->providersLeaveFortify($paths)) {
            return false;
        }
        foreach ($settings['middleware'] as $middleware) {
            if (! $this->guest->guestMiddleware($middleware)) {
                return false;
            }
        }
        if ($route !== null) {
            if (! isset(self::NAMED[$route]) || (self::NAMED[$route][2] !== null && ! in_array(self::NAMED[$route][2], $settings['features'], true))) {
                return false;
            }
            [$pagePath, $view] = self::NAMED[$route];
            $pageName = $route;
        }
        if (! $this->routesLeaveFortify($settings, [[$postName, $postPath], [$pageName, $pagePath]])) {
            return false;
        }
        $registration = $this->appView($view, $paths);
        if ($registration === false) {
            return false;
        }
        if ($registration !== null) {
            return $this->guest->rendersFormStatus($registration, $keys);
        }

        return $keys === ['status'] && $this->kitPageShowsStatus($view);
    }

    /** The one FLOWS contract the class is bound to, or null when it is bound to none, to more than one, or any registration of that contract is not this literal binding. @param list<string> $paths */
    private function boundContract(string $class, array $paths): ?string
    {
        $bound = [];
        foreach ($paths as $path) {
            try {
                $nodes = $this->source->nodes($path);
            } catch (\PhpParser\Error) {
                return null;
            }
            foreach ((new NodeFinder)->find($nodes, fn (Node $node): bool => ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall) && $node->name instanceof Node\Identifier) as $call) {
                /** @var Node\Expr\MethodCall|Node\Expr\StaticCall $call */
                $name = strtolower($call->name->toString());
                if (! in_array($name, ['singleton', 'bind', 'instance', 'scoped', 'bindif', 'singletonif', 'scopedif', 'extend'], true)) {
                    continue;
                }
                $arguments = $call->getArgs();
                $contract = self::classConstant($arguments[0]->value ?? null);
                if ($contract === null || ! isset(self::FLOWS[$contract])) {
                    continue;
                }
                $literal = in_array($name, ['singleton', 'bind', 'instance'], true) && count($arguments) === 2 && $arguments[0]->name === null && $arguments[1]->name === null
                    && $call instanceof Node\Expr\MethodCall && $call->var instanceof Node\Expr\PropertyFetch && $call->var->var instanceof Node\Expr\Variable
                    && $call->var->var->name === 'this' && $call->var->name instanceof Node\Identifier && $call->var->name->toString() === 'app';
                $bound[$contract][] = $literal ? self::classConstant($arguments[1]->value) : null;
            }
        }
        $mine = array_keys(array_filter($bound, fn (array $targets): bool => in_array($class, $targets, true)));
        if (count($mine) !== 1 || $bound[$mine[0]] !== [$class]) {
            return null;
        }

        return $mine[0];
    }

    private static function classConstant(?Node\Expr $value): ?string
    {
        return $value instanceof Node\Expr\ClassConstFetch && $value->class instanceof Node\Name && $value->name instanceof Node\Identifier && strtolower($value->name->toString()) === 'class'
            ? $value->class->toString()
            : null;
    }

    /**
     * config/fortify.php's literal settings, Fortify's defaults for absent keys; false when it is missing, does not parse, or any key read is not literal.
     *
     * @return array{prefix: string, middleware: list<Node\Expr>, guard: string, features: list<string>, paths: array<string, string>}|false
     */
    private function settings(): array|false
    {
        if ($this->settings !== null) {
            return $this->settings;
        }
        $this->settings = false;
        $file = $this->config->base.'/config/fortify.php';
        if (! is_file($file) || is_link($file)) {
            return false;
        }
        try {
            $nodes = $this->source->nodes('config/fortify.php');
        } catch (\PhpParser\Error) {
            return false;
        }
        $returns = array_values(array_filter($nodes, fn (Node $node): bool => $node instanceof Node\Stmt\Return_));
        $array = $returns[0]->expr ?? null;
        if (count($returns) !== 1 || ! $array instanceof Node\Expr\Array_) {
            return false;
        }
        $values = [];
        foreach ($array->items as $item) {
            if ($item === null || $item->unpack || ! $item->key instanceof Node\Scalar\String_ || array_key_exists($item->key->value, $values)) {
                return false;
            }
            $values[$item->key->value] = $item->value;
        }
        $settings = ['prefix' => '', 'middleware' => [new Node\Scalar\String_('web')], 'guard' => 'web', 'features' => [], 'paths' => []];
        if (isset($values['prefix'])) {
            if (! $values['prefix'] instanceof Node\Scalar\String_) {
                return false;
            }
            $settings['prefix'] = trim($values['prefix']->value, '/');
        }
        if (isset($values['guard'])) {
            if (! $values['guard'] instanceof Node\Scalar\String_ || preg_match('~\A[\w.-]+\z~', $values['guard']->value) !== 1) {
                return false;
            }
            $settings['guard'] = $values['guard']->value;
        }
        if (isset($values['views']) && ! ($values['views'] instanceof Node\Expr\ConstFetch && strtolower($values['views']->name->toString()) === 'true')) {
            return false;
        }
        if (isset($values['middleware'])) {
            if (! $values['middleware'] instanceof Node\Expr\Array_) {
                return false;
            }
            $settings['middleware'] = [];
            foreach ($values['middleware']->items as $item) {
                if ($item === null || $item->unpack || $item->key !== null) {
                    return false;
                }
                $settings['middleware'][] = $item->value;
            }
        }
        if (isset($values['paths'])) {
            if (! $values['paths'] instanceof Node\Expr\Array_) {
                return false;
            }
            foreach ($values['paths']->items as $item) {
                if ($item === null || $item->unpack || ! $item->key instanceof Node\Scalar\String_ || ! $item->value instanceof Node\Scalar\String_) {
                    return false;
                }
                $settings['paths'][$item->key->value] = $item->value->value;
            }
        }
        if (! ($values['features'] ?? null) instanceof Node\Expr\Array_) {
            return false;
        }
        foreach ($values['features']->items as $item) {
            $call = $item?->value;
            if (! $item?->unpack && $call instanceof Node\Expr\StaticCall && $call->class instanceof Node\Name && $call->class->toString() === self::FEATURES
                && $call->name instanceof Node\Identifier && ! $call->isFirstClassCallable() && $call->getArgs() === []) {
                $settings['features'][] = $call->name->toString();
            }
        }

        return $this->settings = $settings;
    }

    /** Whether no app provider turns Fortify's routes off, registers string views wholesale, or binds a view response itself. @param list<string> $paths */
    private function providersLeaveFortify(array $paths): bool
    {
        foreach ($paths as $path) {
            foreach ((new NodeFinder)->find($this->source->nodes($path), fn (Node $node): bool => $node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\ClassConstFetch) as $node) {
                if ($node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name && $node->class->toString() === self::FORTIFY
                    && (! $node->name instanceof Node\Identifier || in_array(strtolower($node->name->toString()), ['ignoreroutes', 'viewprefix', 'viewnamespace'], true))) {
                    return false;
                }
                if ($node instanceof Node\Expr\ClassConstFetch && $node->class instanceof Node\Name && preg_match('~\ALaravel\\\\Fortify\\\\Contracts\\\\\w+ViewResponse\z~', $node->class->toString()) === 1) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Whether every route in the route roots is readable and none takes the URI or name of a Fortify route the proof relies on.
     *
     * @param  array{prefix: string, paths: array<string, string>}  $settings
     * @param  list<array{string, string}>  $fortify  [route name, default path]
     */
    private function routesLeaveFortify(array $settings, array $fortify): bool
    {
        $names = [];
        $uris = [];
        foreach ($fortify as [$name, $path]) {
            $names[] = $name;
            $uris[] = trim($settings['prefix'].'/'.trim($settings['paths'][$name] ?? $path, '/'), '/');
        }
        foreach ($this->guest->routes() as $route) {
            if ($route['uri'] === null || $route['name'] === null || in_array($route['uri'], $uris, true) || in_array($route['name'], $names, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The page an app provider's one `Fortify::<view>(...)` registration renders: null when there is none, false when it is not provable
     * (more than one, or not a closure returning only `Inertia::render('<literal>', ...)`).
     *
     * @param  list<string>  $paths
     */
    private function appView(string $view, array $paths): string|false|null
    {
        $calls = [];
        foreach ($paths as $path) {
            $calls = [...$calls, ...$this->viewCalls($this->source->nodes($path), $view)];
        }
        if ($calls === []) {
            return null;
        }

        return count($calls) === 1 ? (self::rendered($calls[0])[0] ?? false) : false;
    }

    /** @param array<Node> $nodes @return list<Node\Expr\StaticCall> */
    private function viewCalls(array $nodes, string $view): array
    {
        /** @var list<Node\Expr\StaticCall> */
        return (new NodeFinder)->find($nodes, fn (Node $node): bool => $node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name && $node->class->toString() === self::FORTIFY
            && $node->name instanceof Node\Identifier && strtolower($node->name->toString()) === strtolower($view));
    }

    /** @return ?array{string, list<Node\Arg>} the literal page and the remaining render arguments, or null when the registration is anything else */
    private static function rendered(Node\Expr\StaticCall $call): ?array
    {
        $arguments = $call->getArgs();
        if ($call->isFirstClassCallable() || count($arguments) !== 1 || $arguments[0]->unpack || $arguments[0]->name !== null) {
            return null;
        }
        $closure = $arguments[0]->value;
        if ($closure instanceof Node\Expr\ArrowFunction) {
            $render = $closure->expr;
        } elseif ($closure instanceof Node\Expr\Closure && count($closure->stmts) === 1 && $closure->stmts[0] instanceof Node\Stmt\Return_) {
            $render = $closure->stmts[0]->expr;
        } else {
            return null;
        }
        if (! $render instanceof Node\Expr\StaticCall || ! $render->class instanceof Node\Name || $render->class->toString() !== 'Inertia\\Inertia' || ! $render->name instanceof Node\Identifier
            || strtolower($render->name->toString()) !== 'render' || $render->isFirstClassCallable()) {
            return null;
        }
        $render = $render->getArgs();
        $page = $render[0] ?? null;
        if ($page === null || $page->unpack || $page->name !== null || ! $page->value instanceof Node\Scalar\String_) {
            return null;
        }

        return [$page->value->value, array_slice($render, 1)];
    }

    /**
     * Whether the app adopted kit's session-ui, kit's SessionUiProvider registers the view as `Inertia::render('<literal>', new <ViewModel>(..., session('status'), ...))`,
     * and that page's tree renders a bare kit `<FormStatus>`, which shows the `status` prop.
     */
    private function kitPageShowsStatus(string $view): bool
    {
        if (! KitShell::adopted($this->config->base, ['session-ui'], ['tey/kit:session-ui']) || ! is_file($this->config->base.'/'.self::KIT_PROVIDER) || is_link($this->config->base.'/'.self::KIT_PROVIDER)) {
            return false;
        }
        try {
            $calls = $this->viewCalls($this->source->nodes(self::KIT_PROVIDER), $view);
        } catch (\PhpParser\Error) {
            return false;
        }
        $rendered = count($calls) === 1 ? self::rendered($calls[0]) : null;
        if ($rendered === null || count($rendered[1]) !== 1 || ! $rendered[1][0]->value instanceof Node\Expr\New_) {
            return false;
        }
        $status = array_filter($rendered[1][0]->value->getArgs(), fn (Node\Arg $argument): bool => $argument->value instanceof Node\Expr\FuncCall && $argument->value->name instanceof Node\Name
            && $argument->value->name->toString() === 'session' && count($argument->value->getArgs()) === 1
            && $argument->value->getArgs()[0]->value instanceof Node\Scalar\String_ && $argument->value->getArgs()[0]->value->value === 'status');
        $page = $this->guest->pageFile($rendered[0]);
        if ($status === [] || $page === null) {
            return false;
        }

        return $this->guest->pageTreeReaches($page, fn (string $path, string $source): bool => self::rendersBareKitFormStatus($source));
    }

    /** Whether the file imports FormStatus (not renamed) from `@kit/ui`, `@/components/Kit` or `@/components/Kit/Form` and renders a `<FormStatus>` with no message or flash-key. */
    private static function rendersBareKitFormStatus(string $source): bool
    {
        if (preg_match('~\bimport\s*\{[^}]*(?<![\w$])FormStatus(?![\w$])(?!\s+as\b)[^}]*\}\s*from\s*([\'"])(?:@kit/ui|@/components/Kit(?:/Form)?)\1~', VueImports::code(VueImports::scripts($source))) !== 1) {
            return false;
        }
        preg_match_all('~<FormStatus\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>~', FrontendSource::template($source), $tags);
        foreach ($tags[1] as $attributes) {
            if (preg_match('~(?<![\w-])(?:message|flash-key|flashKey|v-bind)(?![\w-])~', $attributes) !== 1) {
                return true;
            }
        }

        return false;
    }
}
