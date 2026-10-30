<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Tey\Standards\Configuration;
use Tey\Standards\Source;

final class Toast
{
    public function __construct(private readonly Configuration $config, private readonly Source $source) {}

    /** @return list<array{target: string, check: string}> */
    public function violations(): array
    {
        $violations = [];
        $finder = new NodeFinder;
        foreach ($this->source->files($this->config->sourceRoots) as $path => $text) {
            if ($this->source->inside($path, $this->config->uiRoots)) {
                continue;
            }
            foreach ($finder->find($this->source->nodes($path), fn (Node $node): bool => $node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall) as $call) {
                if ((! $call instanceof Node\Expr\MethodCall && ! $call instanceof Node\Expr\StaticCall) || $call->isFirstClassCallable() || ! $call->name instanceof Node\Identifier) {
                    continue;
                }
                $method = strtolower($call->name->toString());
                $outcome = self::flashesOutcome($call);
                $session = $call instanceof Node\Expr\MethodCall && $call->var instanceof Node\Expr\FuncCall && $call->var->name instanceof Node\Name && $call->var->name->toString() === 'session';
                $facade = $call instanceof Node\Expr\StaticCall && $call->class instanceof Node\Name ? $call->class->toString() : null;
                // A guest form's back()->with('<key>', ...) that a FormStatus on the page it returns to shows (GuestFormStatus) is not a loose flash.
                if ($method === 'with' && $outcome && $this->formStatusFlash($call)) {
                    continue;
                }
                if (($method === 'with' && $outcome) || ($method === 'flash' && ($session || $facade === 'Illuminate\Support\Facades\Session' || ($facade === 'Inertia\Inertia' && $outcome)))) {
                    $violations[] = ['target' => $path, 'check' => 'flash outcomes through Toastable, not session keys'];
                }
            }
        }
        $invokables = $this->mutatingInvokables();
        foreach ($this->source->files($this->config->controllerRoots) as $path => $text) {
            if ($this->source->inside($path, $this->config->uiRoots)) {
                continue;
            }
            foreach ($finder->findInstanceOf($this->source->nodes($path), Node\Stmt\Class_::class) as $class) {
                foreach ($class->getMethods() as $method) {
                    $name = $method->name->toString();
                    if (! $method->isPublic() || (! in_array($name, ['store', 'update', 'destroy'], true) && ! ($name === '__invoke' && isset($class->namespacedName) && in_array($class->namespacedName->toString(), $invokables, true)))) {
                        continue;
                    }
                    // kit 0.4.6+ headless toasts flash before the return: $this->toast(...) (Toastable) or Toast::success(...).
                    if ($this->flashesKitToast($method->stmts ?? [])) {
                        continue;
                    }
                    $this->assignments = $this->assignments($method->stmts ?? []);
                    foreach ($method->params as $param) {
                        if ($param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                            $this->assignments[$param->var->name][] = new Node\Expr\ConstFetch(new Node\Name('null'));
                        }
                    }
                    foreach ($this->returns($method->stmts ?? []) as $return) {
                        // A guest form's back() to a page that renders kit's <FormStatus> confirms in place: no toast (GuestFormStatus).
                        if ($return->expr !== null && $this->redirectWithoutToast($return->expr) && ! (($target = self::returnFlashKeys($method, $return)) !== null && $this->formStatus()->proves($class, $name, ...$target))) {
                            $violations[] = ['target' => $path, 'check' => $class->name.'::'.$name.' redirect must chain ->toast*()'];
                        }
                    }
                }
            }
        }

        return array_values(array_unique([...$this->formStatus()->configurationErrors(), ...$violations], SORT_REGULAR));
    }

    private const OUTCOME_KEYS = ['status', 'success', 'message', 'error', 'flash'];

    /**
     * Whether a `with(...)` / `flash(...)` call's first argument may name an outcome key: a literal outcome key, an array with a literal
     * outcome key, or, on a chain provably rooted in a redirect or response, anything the rule can't read key by key (a spread, a non-literal
     * array key, a lone non-array argument such as `->with($data)`, a non-literal key in `->with($key, $value)`), which fails closed. Implicit and integer keys are literal and never outcomes.
     */
    private static function flashesOutcome(Node\Expr\MethodCall|Node\Expr\StaticCall $call): bool
    {
        $arguments = $call->getArgs();
        $first = $arguments[0] ?? null;
        if ($first === null) {
            return false;
        }
        $redirect = self::redirectRooted($call);
        if ($first->unpack) {
            return $redirect;
        }
        $key = $first->value;
        if ($key instanceof Node\Scalar\String_) {
            return in_array($key->value, self::OUTCOME_KEYS, true);
        }
        if ($key instanceof Node\Expr\Array_) {
            foreach ($key->items as $item) {
                if ($item === null || $item->unpack || ($item->key !== null && ! $item->key instanceof Node\Scalar\String_ && ! $item->key instanceof Node\Scalar\Int_)) {
                    if ($redirect) {
                        return true;
                    }

                    continue;
                }
                if ($item->key instanceof Node\Scalar\String_ && in_array($item->key->value, self::OUTCOME_KEYS, true)) {
                    return true;
                }
            }

            return false;
        }

        return $redirect && $first->name === null && ! $key instanceof Node\Scalar && ! $key instanceof Node\Expr\Closure && ! $key instanceof Node\Expr\ArrowFunction;
    }

    /**
     * Whether a call's chain provably starts at a redirect or response: `redirect()`, `back()`, `to_route()`, `response()`, `Redirect::*`,
     * `Inertia::*`, or a variable that every assignment in the enclosing function sets to such a chain. Models, query builders, unknown
     * variables and method results are not.
     */
    private static function redirectRooted(Node\Expr $expression, int $depth = 0): bool
    {
        while ($expression instanceof Node\Expr\MethodCall || $expression instanceof Node\Expr\NullsafeMethodCall) {
            $expression = $expression->var;
        }
        if ($expression instanceof Node\Expr\FuncCall) {
            return $expression->name instanceof Node\Name && in_array(strtolower($expression->name->toString()), ['redirect', 'back', 'to_route', 'response'], true);
        }
        if ($expression instanceof Node\Expr\StaticCall) {
            return $expression->class instanceof Node\Name && in_array($expression->class->toString(), ['Illuminate\Support\Facades\Redirect', 'Illuminate\Support\Facades\Response', 'Inertia\Inertia'], true);
        }
        if (! $expression instanceof Node\Expr\Variable || ! is_string($expression->name) || $depth > 3) {
            return false;
        }
        $scope = $expression->getAttribute('parent');
        while ($scope instanceof Node && ! $scope instanceof Node\FunctionLike) {
            $scope = $scope->getAttribute('parent');
        }
        if (! $scope instanceof Node\FunctionLike) {
            return false;
        }
        $name = $expression->name;
        $assigned = (new NodeFinder)->find($scope->getStmts() ?? [], fn (Node $node): bool => ($node instanceof Node\Expr\Assign || $node instanceof Node\Expr\AssignRef) && $node->var instanceof Node\Expr\Variable && $node->var->name === $name);
        foreach ($scope->getParams() as $param) {
            if ($param->var instanceof Node\Expr\Variable && $param->var->name === $name) {
                return false;
            }
        }
        if ($assigned === []) {
            return false;
        }
        foreach ($assigned as $assign) {
            if (! $assign instanceof Node\Expr\Assign || ! self::redirectRooted($assign->expr, $depth + 1)) {
                return false;
            }
        }

        return true;
    }

    private ?GuestFormStatus $formStatus = null;

    private function formStatus(): GuestFormStatus
    {
        return $this->formStatus ??= new GuestFormStatus($this->config, $this->source);
    }

    private ?FortifyFormStatus $fortifyFormStatus = null;

    private function fortifyFormStatus(): FortifyFormStatus
    {
        return $this->fortifyFormStatus ??= new FortifyFormStatus($this->config, $this->source, $this->formStatus());
    }

    /**
     * Whether a `->with(...)` call is part of `return back()->with(...)` in a public method of a class (not in a closure), and
     * GuestFormStatus proves that method a guest form whose page shows every flashed key through `<FormStatus>`.
     */
    private function formStatusFlash(Node\Expr\MethodCall $call): bool
    {
        $top = $call;
        while (($parent = $top->getAttribute('parent')) instanceof Node\Expr\MethodCall && $parent->var === $top) {
            $top = $parent;
        }
        $return = $top->getAttribute('parent');
        // Fortify's own shape: `return $request->wantsJson() ? new JsonResponse(...) : <redirect>;` (or negated and reversed); toResponse() only.
        $ternary = null;
        if ($return instanceof Node\Expr\Ternary && $return->getAttribute('parent') instanceof Node\Stmt\Return_) {
            $ternary = $return;
            $return = $return->getAttribute('parent');
        }
        $method = null;
        if ($return instanceof Node\Stmt\Return_) {
            // The nearest enclosing function: a closure or arrow function in between means the return is not the action's.
            $method = $return->getAttribute('parent');
            while ($method instanceof Node && ! $method instanceof Node\FunctionLike) {
                $method = $method->getAttribute('parent');
            }
        }
        $class = $method instanceof Node\Stmt\ClassMethod ? $method->getAttribute('parent') : null;
        if (! $method instanceof Node\Stmt\ClassMethod || ! $method->isPublic() || ! $class instanceof Node\Stmt\Class_) {
            return false;
        }
        // A Fortify response bound by the app: toResponse() is read like an action, its top-level Inertia::flash keys included (FortifyFormStatus).
        if ($ternary !== null && ($method->name->toLowerString() !== 'toresponse' || self::jsonOrRedirect($method, $ternary) !== $top)) {
            return false;
        }
        if ($method->name->toLowerString() === 'toresponse' && $return instanceof Node\Stmt\Return_) {
            // Its body is top-level Inertia::flash statements, then this one return: no other branch or return (an `if (wantsJson())` form included).
            $body = $method->stmts ?? [];
            if (end($body) !== $return || array_filter(array_slice($body, 0, -1), fn (Node\Stmt $statement): bool => ! ($statement instanceof Node\Stmt\Expression && $statement->expr instanceof Node\Expr\StaticCall && self::inertiaFlash($statement->expr))) !== []) {
                return false;
            }
            $target = self::returnFlashKeys($method, $return, $top);

            return $target !== null && $this->fortifyFormStatus()->proves($class, $method->name->toString(), ...$target);
        }
        $target = GuestFormStatus::redirectFlash($top);

        return $target !== null && $target[0] !== [] && $this->formStatus()->proves($class, $method->name->toString(), ...$target);
    }

    /**
     * The keys a guest `back()` or literal named-route redirect (GuestFormStatus::redirectFlash()) return flashes, with the route name: its own `->with(...)` keys plus the literal keys of every `Inertia::flash('<key>', ...)` /
     * `Inertia::flash(['<key>' => ...])` written as a top-level statement of the method before the top-level statement holding the return,
     * so it runs on every path to it. Null (unprovable) when the return is not such a redirect, or when the method holds any other
     * `Inertia::flash` outside closures: one inside an if, loop, try or other expression, or one whose keys are not literal identifiers.
     *
     * @return ?array{list<string>, ?string}
     */
    private static function returnFlashKeys(Node\Stmt\ClassMethod $method, Node\Stmt\Return_ $return, ?Node\Expr $expression = null): ?array
    {
        $expression ??= $return->expr;
        $target = $expression === null ? null : GuestFormStatus::redirectFlash($expression);
        if ($target === null) {
            return null;
        }
        [$keys, $route] = $target;
        $top = $return;
        while (($parent = $top->getAttribute('parent')) instanceof Node && $parent !== $method) {
            $top = $parent;
        }
        $counted = [];
        $before = true;
        foreach ($method->stmts ?? [] as $statement) {
            if ($statement === $top) {
                $before = false;
            }
            $call = $statement instanceof Node\Stmt\Expression ? $statement->expr : null;
            if ($before && $call instanceof Node\Expr\StaticCall && self::inertiaFlash($call)) {
                $named = GuestFormStatus::withKeys($call);
                if ($named === null) {
                    return null;
                }
                $keys = [...$keys, ...$named];
                $counted[] = $call;
            }
        }
        $all = array_filter(self::outsideClosures($method->stmts ?? []), fn (Node $node): bool => $node instanceof Node\Expr\StaticCall && self::inertiaFlash($node));
        foreach ($all as $flash) {
            if (! in_array($flash, $counted, true) && ! ($flash->getAttribute('parent') instanceof Node\Stmt\Expression && $flash->getAttribute('parent')->getAttribute('parent') === $method)) {
                return null;
            }
        }

        return [array_values(array_unique($keys)), $route];
    }

    /**
     * The redirect branch of Fortify's response shape, `$request->wantsJson() ? new JsonResponse(...) : <redirect>` or
     * `! $request->wantsJson() ? <redirect> : new JsonResponse(...)`, where `$request` is the method's first parameter (laravel/fortify 1.41's
     * own password-reset responses). Null for any other condition, a short ternary, or a JSON branch other than `new JsonResponse(...)`.
     */
    private static function jsonOrRedirect(Node\Stmt\ClassMethod $method, Node\Expr\Ternary $ternary): ?Node\Expr
    {
        $parameter = $method->params[0]->var ?? null;
        if ($ternary->if === null || ! $parameter instanceof Node\Expr\Variable || ! is_string($parameter->name)) {
            return null;
        }
        $condition = $ternary->cond;
        $negated = $condition instanceof Node\Expr\BooleanNot;
        $call = $negated ? $condition->expr : $condition;
        if (! $call instanceof Node\Expr\MethodCall || ! $call->var instanceof Node\Expr\Variable || $call->var->name !== $parameter->name
            || ! $call->name instanceof Node\Identifier || $call->name->toLowerString() !== 'wantsjson' || $call->getArgs() !== [] || $call->isFirstClassCallable()) {
            return null;
        }
        [$json, $redirect] = $negated ? [$ternary->else, $ternary->if] : [$ternary->if, $ternary->else];

        return $json instanceof Node\Expr\New_ && $json->class instanceof Node\Name && $json->class->toString() === 'Illuminate\Http\JsonResponse' ? $redirect : null;
    }

    private static function inertiaFlash(Node\Expr\StaticCall $call): bool
    {
        return $call->class instanceof Node\Name && $call->class->toString() === 'Inertia\Inertia' && $call->name instanceof Node\Identifier && strtolower($call->name->toString()) === 'flash';
    }

    /** @param array<Node> $nodes
     * @return array<Node> the nodes with every closure, arrow function and nested declaration dropped */
    private static function outsideClosures(array $nodes): array
    {
        $kept = [];
        foreach ($nodes as $node) {
            if ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction || $node instanceof Node\Stmt\ClassLike || $node instanceof Node\Stmt\Function_) {
                continue;
            }
            $kept[] = $node;
            foreach ($node->getSubNodeNames() as $key) {
                $child = $node->$key;
                if ($child instanceof Node) {
                    $kept = [...$kept, ...self::outsideClosures([$child])];
                } elseif (is_array($child)) {
                    $kept = [...$kept, ...self::outsideClosures(array_values(array_filter($child, fn ($value): bool => $value instanceof Node)))];
                }
            }
        }

        return $kept;
    }

    /** @param array<Node> $nodes
     * @return list<Node\Stmt\Return_>
     */
    private function returns(array $nodes): array
    {
        $returns = [];
        foreach ($nodes as $node) {
            if ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction || $node instanceof Node\Stmt\ClassLike || $node instanceof Node\Stmt\Function_) {
                continue;
            }
            if ($node instanceof Node\Stmt\Return_) {
                $returns[] = $node;

                continue;
            }
            foreach ($node->getSubNodeNames() as $key) {
                $child = $node->$key;
                if ($child instanceof Node) {
                    $returns = [...$returns, ...$this->returns([$child])];
                } elseif (is_array($child)) {
                    $returns = [...$returns, ...$this->returns(array_values(array_filter($child, fn ($value): bool => $value instanceof Node)))];
                }
            }
        }

        return $returns;
    }

    /** @param array<Node> $nodes */
    private function flashesKitToast(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction || $node instanceof Node\Stmt\ClassLike || $node instanceof Node\Stmt\Function_) {
                continue;
            }
            if ($node instanceof Node\Expr\MethodCall && $node->var instanceof Node\Expr\Variable && $node->var->name === 'this'
                && $node->name instanceof Node\Identifier && $node->name->toString() === 'toast') {
                return true;
            }
            if ($node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name && $node->class->toString() === 'Tey\Kit\Features\Core\Toast'
                && $node->name instanceof Node\Identifier && in_array(strtolower($node->name->toString()), ['success', 'info', 'warning', 'error'], true)) {
                return true;
            }
            foreach ($node->getSubNodeNames() as $key) {
                $child = $node->$key;
                if (($child instanceof Node && $this->flashesKitToast([$child])) || (is_array($child) && $this->flashesKitToast(array_filter($child, fn ($item): bool => $item instanceof Node)))) {
                    return true;
                }
            }
        }

        return false;
    }

    private function redirectWithoutToast(Node\Expr $expression): bool
    {
        if ($expression instanceof Node\Expr\Ternary) {
            return ($expression->if !== null && $this->redirectWithoutToast($expression->if)) || $this->redirectWithoutToast($expression->else);
        }
        if ($expression instanceof Node\Expr\Match_) {
            foreach ($expression->arms as $arm) {
                if ($this->redirectWithoutToast($arm->body)) {
                    return true;
                }
            }

            return false;
        }
        $toast = false;
        $first = null;
        while ($expression instanceof Node\Expr\MethodCall || $expression instanceof Node\Expr\NullsafeMethodCall) {
            $toast = $toast || ($expression->name instanceof Node\Identifier && preg_match('/^toast(?:[A-Z].*)?$/', $expression->name->toString()) === 1);
            $first = $expression;
            $expression = $expression->var;
        }
        // An off-site redirect leaves the app, so no page shows a toast: redirect()->away(<external URL>) or Redirect::away(<external URL>).
        $away = match (true) {
            $expression instanceof Node\Expr\FuncCall && $expression->name instanceof Node\Name && $expression->name->toString() === 'redirect' && $expression->getArgs() === []
                && $first instanceof Node\Expr\MethodCall && $first->name instanceof Node\Identifier && strtolower($first->name->toString()) === 'away' && ! $first->isFirstClassCallable() => $first,
            $expression instanceof Node\Expr\StaticCall && $expression->class instanceof Node\Name && $expression->class->toString() === 'Illuminate\Support\Facades\Redirect'
                && $expression->name instanceof Node\Identifier && strtolower($expression->name->toString()) === 'away' && ! $expression->isFirstClassCallable() => $expression,
            default => null,
        };
        if ($away !== null && $this->offSite($away->getArgs()[0] ?? null)) {
            return false;
        }
        $redirect = ($expression instanceof Node\Expr\FuncCall && $expression->name instanceof Node\Name && in_array($expression->name->toString(), ['redirect', 'back', 'to_route'], true))
            || ($expression instanceof Node\Expr\StaticCall && $expression->class instanceof Node\Name && $expression->class->toString() === 'Illuminate\Support\Facades\Redirect');

        return $redirect && ! $toast;
    }

    /** @var array<string, list<Node\Expr>> variable => every value the action assigns it */
    private array $assignments = [];

    /**
     * Whether the away() target is provably another host. Only these count:
     *  - an absolute http(s) URL literal whose host is not the app's (APP_URL in .env or .env.example, or config/app.php's literal url);
     *  - a concatenation or interpolated string that starts with such a literal (the scheme and host are fixed, the rest varies);
     *  - a variable the action assigns exactly once, to one of those, and never takes as a parameter or by reference.
     * Anything else (a relative path, the app's own host, url()/route()/config(), a parameter, a property, a method's result) may be internal and still needs the toast.
     */
    private function offSite(?Node\Arg $argument, int $depth = 0): bool
    {
        if ($argument === null || $argument->unpack || $argument->byRef || ($argument->name !== null && $argument->name->toString() !== 'path')) {
            return false;
        }

        return $this->external($argument->value, $depth);
    }

    private function external(Node\Expr $value, int $depth = 0): bool
    {
        if ($value instanceof Node\Scalar\String_) {
            return $this->externalUrl($value->value, true);
        }
        if ($value instanceof Node\Expr\BinaryOp\Concat) {
            $left = $value;
            while ($left instanceof Node\Expr\BinaryOp\Concat) {
                $left = $left->left;
            }

            return $left instanceof Node\Scalar\String_ && $this->externalUrl($left->value, false);
        }
        if ($value instanceof Node\Scalar\InterpolatedString) {
            $head = $value->parts[0] ?? null;

            return $head instanceof Node\InterpolatedStringPart && $this->externalUrl($head->value, false);
        }
        if ($value instanceof Node\Expr\Variable && is_string($value->name) && ! isset($this->assignments['*']) && $depth < 5) {
            $assigned = $this->assignments[$value->name] ?? [];

            return count($assigned) === 1 && $this->external($assigned[0], $depth + 1);
        }

        return false;
    }

    /** An absolute http(s) URL on a host other than the app's. A prefix must reach the end of the host (a `/`, `?` or `#` follows it). */
    private function externalUrl(string $url, bool $whole): bool
    {
        if (preg_match('~^https?://([^/?#@\s:]+)(?::\d+)?([/?#]|$)~i', $url, $match) !== 1 || (! $whole && $match[2] === '')) {
            return false;
        }

        return ! in_array(strtolower($match[1]), $this->appHosts(), true);
    }

    /** @var list<string>|null */
    private ?array $hosts = null;

    /** @return list<string> the app's own hosts, from APP_URL in .env and .env.example and config/app.php's literal default */
    private function appHosts(): array
    {
        if ($this->hosts !== null) {
            return $this->hosts;
        }
        $hosts = [];
        foreach (['.env', '.env.example'] as $file) {
            $text = is_file($this->config->base.'/'.$file) ? (string) file_get_contents($this->config->base.'/'.$file) : '';
            if (preg_match_all('/^\s*APP_URL\s*=\s*["\']?([^"\'\s#]+)/m', $text, $matches) > 0) {
                $hosts = [...$hosts, ...$matches[1]];
            }
        }
        $config = $this->config->base.'/config/app.php';
        if (is_file($config) && preg_match_all('/[\'"]url[\'"]\s*=>\s*(?:env\(\s*[\'"]APP_URL[\'"]\s*,\s*)?[\'"]([^\'"]+)[\'"]/', (string) file_get_contents($config), $matches) > 0) {
            $hosts = [...$hosts, ...$matches[1]];
        }

        return $this->hosts = array_values(array_unique(array_filter(array_map(fn (string $url): ?string => is_string($host = parse_url($url, PHP_URL_HOST)) ? strtolower($host) : null, $hosts))));
    }

    /**
     * Every value the action assigns each variable, outside closures. A parameter, a by-reference or list assignment, a compound assignment,
     * a foreach target, a static or global, a catch variable, or passing it to any call but away() (which may take it by reference) adds an unknown entry, so the variable never counts as provably external.
     *
     * @param  array<Node>  $nodes
     * @return array<string, list<Node\Expr>>
     */
    private function assignments(array $nodes): array
    {
        $assigned = [];
        $unknown = new Node\Expr\ConstFetch(new Node\Name('null'));
        $visit = function (array $nodes) use (&$visit, &$assigned, $unknown): void {
            foreach ($nodes as $node) {
                if (! $node instanceof Node || $node instanceof Node\Stmt\ClassLike || $node instanceof Node\Stmt\Function_) {
                    continue;
                }
                $mark = function (?Node $target, Node\Expr $value) use (&$assigned, $unknown): void {
                    if ($target instanceof Node\Expr\Variable) {
                        // A variable variable ($$name = ...) could be any of them.
                        $assigned[is_string($target->name) ? $target->name : '*'][] = $value;
                    } elseif ($target instanceof Node\Expr\List_ || $target instanceof Node\Expr\Array_) {
                        foreach ($target->items as $item) {
                            if ($item !== null && $item->value instanceof Node\Expr\Variable && is_string($item->value->name)) {
                                $assigned[$item->value->name][] = $unknown;
                            }
                        }
                    }
                };
                if ($node instanceof Node\Expr\Assign) {
                    $node->var instanceof Node\Expr\Variable ? $mark($node->var, $node->expr) : $mark($node->var, $unknown);
                } elseif ($node instanceof Node\Expr\AssignRef || $node instanceof Node\Expr\AssignOp) {
                    $mark($node->var, $unknown);
                    $node instanceof Node\Expr\AssignRef && $mark($node->expr, $unknown);
                } elseif ($node instanceof Node\Expr\CallLike && ! ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall ? $node->name instanceof Node\Identifier && strtolower($node->name->toString()) === 'away' : false) && ! $node->isFirstClassCallable()) {
                    // A call may take a variable by reference and change it (preg_match, parse_str, a method's &$out).
                    foreach ($node->getArgs() as $arg) {
                        $mark($arg->value, $unknown);
                    }
                } elseif ($node instanceof Node\Stmt\Foreach_) {
                    $mark($node->valueVar, $unknown);
                    $mark($node->keyVar, $unknown);
                } elseif ($node instanceof Node\Stmt\Static_ || $node instanceof Node\Stmt\Global_) {
                    foreach ($node instanceof Node\Stmt\Static_ ? array_map(fn ($var) => $var->var, $node->vars) : $node->vars as $var) {
                        $mark($var, $unknown);
                    }
                } elseif ($node instanceof Node\Stmt\Catch_) {
                    $mark($node->var, $unknown);
                } elseif ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) {
                    foreach ($node instanceof Node\Expr\Closure ? $node->uses : [] as $use) {
                        if ($use->byRef) {
                            $mark($use->var, $unknown);
                        }
                    }

                    continue;
                }
                foreach ($node->getSubNodeNames() as $key) {
                    $child = $node->$key;
                    $visit($child instanceof Node ? [$child] : (is_array($child) ? $child : []));
                }
            }
        };
        $visit($nodes);

        return $assigned;
    }

    private function groupController(Node $node): ?string
    {
        for ($parent = $node->getAttribute('parent'); $parent instanceof Node; $parent = $parent->getAttribute('parent')) {
            if (! $parent instanceof Node\Expr\MethodCall || ! $parent->name instanceof Node\Identifier || strtolower($parent->name->toString()) !== 'group') {
                continue;
            }
            $builder = $parent->var;
            while ($builder instanceof Node\Expr\MethodCall || $builder instanceof Node\Expr\StaticCall) {
                if ($builder->name instanceof Node\Identifier && strtolower($builder->name->toString()) === 'controller' && ! $builder->isFirstClassCallable()) {
                    $controller = $builder->getArgs()[0]->value ?? null;
                    if ($controller instanceof Node\Expr\ClassConstFetch && $controller->class instanceof Node\Name) {
                        return $controller->class->toString();
                    }
                }
                if (! $builder instanceof Node\Expr\MethodCall) {
                    break;
                }
                $builder = $builder->var;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function mutatingInvokables(): array
    {
        $classes = [];
        foreach ($this->source->files($this->config->routeRoots) as $path => $text) {
            foreach ((new NodeFinder)->find($this->source->nodes($path), fn (Node $node): bool => $node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\MethodCall) as $call) {
                if ((! $call instanceof Node\Expr\MethodCall && ! $call instanceof Node\Expr\StaticCall) || $call->isFirstClassCallable() || ! $call->name instanceof Node\Identifier || ! in_array(strtolower($call->name->toString()), ['post', 'put', 'patch', 'delete', 'options', 'match', 'any'], true)) {
                    continue;
                }
                $root = $call;
                while ($root instanceof Node\Expr\MethodCall) {
                    $root = $root->var;
                }
                if (! $root instanceof Node\Expr\StaticCall || ! $root->class instanceof Node\Name || $root->class->toString() !== 'Illuminate\Support\Facades\Route') {
                    continue;
                }
                $arguments = $call->getArgs();
                if (strtolower($call->name->toString()) === 'match') {
                    $verbs = $arguments[0]->value ?? null;
                    if ($verbs instanceof Node\Expr\Array_ && array_filter($verbs->items, fn ($item): bool => $item->value instanceof Node\Scalar\String_ && ! in_array(strtoupper($item->value->value), ['GET', 'HEAD'], true)) === []) {
                        continue;
                    }
                }
                $action = $arguments[strtolower($call->name->toString()) === 'match' ? 2 : 1]->value ?? null;
                if ($action instanceof Node\Expr\Array_) {
                    $method = $action->items[1]->value ?? null;
                    $action = $method instanceof Node\Scalar\String_ && $method->value === '__invoke' ? ($action->items[0]->value ?? null) : null;
                }
                if ($action instanceof Node\Expr\ClassConstFetch && $action->class instanceof Node\Name && $action->name instanceof Node\Identifier && strtolower($action->name->toString()) === 'class') {
                    $classes[] = $action->class->toString();
                } elseif ($action instanceof Node\Scalar\String_) {
                    if ($action->value === '__invoke') {
                        $controller = $this->groupController($call);
                        if ($controller !== null) {
                            $classes[] = $controller;
                        }
                    } elseif (! str_contains($action->value, '@') || str_ends_with($action->value, '@__invoke')) {
                        $classes[] = ltrim(str_replace('@__invoke', '', $action->value), '\\');
                    }
                }
            }
        }

        return array_values(array_unique($classes));
    }
}
