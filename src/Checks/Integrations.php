<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Tey\Standards\Configuration;
use Tey\Standards\Source;

/**
 * Rule `integrations` (patterns integrations.webhook and integrations.api-client), advisory unless listed in Configuration::optionalRules:
 *
 * - A route whose URI mentions `webhook` must reach a handler that verifies its sender: spatie/laravel-webhook-client (`Route::webhooks()`, or a
 *   controller that runs WebhookProcessor) with a configured signature validator, Cashier's webhook controller (or a subclass of it), or a controller
 *   whose source verifies a signature (hash_equals, a SignatureValidator, a verify call, Stripe's constructEvent). One delegation is followed: a call on
 *   an injected dependency (`$handler->handleWebhook(...)`) counts when that dependency's called method verifies, or it extends a verifying controller.
 * - App code calls another service through a contract: an `Http::` call in a class that implements no interface (a service provider, which defines
 *   macros, is exempt) should move behind one, with a fake for tests.
 *
 * Both are static and read names, not behaviour: a verifier reached another way is not seen, and a class that implements an unrelated interface
 * passes. That is why it is advisory.
 */
final class Integrations
{
    private const VERIFIED_CONTROLLERS = ['Laravel\Cashier\Http\Controllers\WebhookController', 'Spatie\WebhookClient\Http\Controllers\WebhookController'];

    private const VERIFIES = ['hash_equals', 'SignatureValidator', 'WebhookProcessor', 'verify', 'VerifyWebhookSignature', 'constructEvent', 'isValid'];

    public function __construct(private readonly Configuration $config, private readonly Source $source) {}

    /** @return list<array{target: string, check: string}> */
    public function violations(): array
    {
        return array_values(array_unique([...$this->webhookRoutes(), ...$this->inlineHttp()], SORT_REGULAR));
    }

    /** @return list<array{target: string, check: string}> */
    private function webhookRoutes(): array
    {
        $violations = [];
        $finder = new NodeFinder;
        $configured = $this->webhookConfigs();
        foreach ($this->source->files($this->config->routeRoots) as $path => $text) {
            foreach ($finder->findInstanceOf($this->source->nodes($path), Node\Expr\StaticCall::class) as $call) {
                if (! $call->class instanceof Node\Name || ! in_array(ltrim($call->class->toString(), '\\'), ['Route', 'Illuminate\Support\Facades\Route'], true) || ! $call->name instanceof Node\Identifier) {
                    continue;
                }
                $method = $call->name->toString();
                $args = $call->getArgs();
                $uri = isset($args[0]) && $args[0]->value instanceof Node\Scalar\String_ ? $args[0]->value->value : null;
                if ($method === 'webhooks') {
                    $name = isset($args[1]) && $args[1]->value instanceof Node\Scalar\String_ ? $args[1]->value->value : 'default';
                    if (! ($configured[$name] ?? false)) {
                        $violations[] = ['target' => $path, 'check' => "webhook route {$uri} has no signature validator configured for {$name} in config/webhook-client.php"];
                    }

                    continue;
                }
                if (! in_array($method, ['post', 'put', 'patch', 'any', 'match'], true) || $uri === null || stripos($uri, 'webhook') === false) {
                    continue;
                }
                $action = $args[$method === 'match' ? 2 : 1]->value ?? null;
                $controller = match (true) {
                    $action instanceof Node\Expr\ClassConstFetch && $action->class instanceof Node\Name => ltrim($action->class->toString(), '\\'),
                    $action instanceof Node\Expr\Array_ && ($action->items[0]->value ?? null) instanceof Node\Expr\ClassConstFetch && $action->items[0]->value->class instanceof Node\Name => ltrim($action->items[0]->value->class->toString(), '\\'),
                    default => null,
                };
                if ($controller === null || ! $this->verifies($controller)) {
                    $violations[] = ['target' => $path, 'check' => "webhook route {$uri} does not go through a signature validator"];
                }
            }
        }

        return $violations;
    }

    /** @return list<array{target: string, check: string}> */
    private function inlineHttp(): array
    {
        $violations = [];
        $finder = new NodeFinder;
        foreach ($this->source->files($this->config->sourceRoots) as $path => $text) {
            if (! str_contains($text, 'Http::')) {
                continue;
            }
            foreach ($finder->findInstanceOf($this->source->nodes($path), Node\Stmt\Class_::class) as $class) {
                if ($class->implements !== [] || ($class->extends !== null && str_ends_with($class->extends->toString(), 'ServiceProvider'))) {
                    continue;
                }
                $calls = $finder->find($class->stmts, fn (Node $node): bool => $node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name && in_array(ltrim($node->class->toString(), '\\'), ['Http', 'Illuminate\Support\Facades\Http'], true));
                if ($calls !== []) {
                    $violations[] = ['target' => $path, 'check' => ($class->namespacedName?->toString() ?? (string) $class->name).' calls Http:: inline: put the call behind a contract (an interface this class implements), with a fake for tests'];
                }
            }
        }

        return $violations;
    }

    /**
     * Whether the routed class verifies its sender: it is (or extends) a verifying controller (Cashier's WebhookController registers
     * VerifyWebhookSignature in its constructor, so a subclass inherits it), its source names a verifier, or it follows ONE delegation:
     * a call on an injected dependency (a typed parameter or property, `$handler->handleWebhook(...)`) whose called method verifies.
     */
    private function verifies(string $controller): bool
    {
        if (in_array($controller, self::VERIFIED_CONTROLLERS, true)) {
            return true;
        }

        $class = $this->classNode($controller);

        if ($class === null) {
            return false;
        }

        if ($this->extendsVerified($class) || $this->names($this->text($controller), $class)) {
            return true;
        }

        foreach ($this->delegations($class) as [$dependency, $method]) {
            if ($this->methodVerifies($dependency, $method)) {
                return true;
            }
        }

        return false;
    }

    /** The one delegated hop: the dependency is a verifying controller, extends one, or its called method names a verifier. */
    private function methodVerifies(string $dependency, string $method): bool
    {
        if (in_array($dependency, self::VERIFIED_CONTROLLERS, true)) {
            return true;
        }

        $class = $this->classNode($dependency);

        if ($class === null) {
            return false;
        }

        if ($this->extendsVerified($class)) {
            return true;
        }

        $node = $class->getMethod($method);

        return $node !== null && $this->names($this->text($dependency), $node);
    }

    /**
     * Calls on injected dependencies: `$param->m()` where $param is a typed method parameter, and `$this->prop->m()` where prop is a typed
     * (or constructor-promoted) property. Only app classes and the verifying controllers are worth following.
     *
     * @return list<array{0: string, 1: string}> [dependency class, method]
     */
    private function delegations(Node\Stmt\Class_ $class): array
    {
        $finder = new NodeFinder;
        $properties = [];

        foreach ($class->getProperties() as $property) {
            if ($property->type instanceof Node\Name) {
                foreach ($property->props as $prop) {
                    $properties[$prop->name->toString()] = $property->type->toString();
                }
            }
        }

        $calls = [];

        foreach ($class->getMethods() as $method) {
            $parameters = [];

            foreach ($method->params as $param) {
                if ($param->type instanceof Node\Name && $param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                    $parameters[$param->var->name] = $param->type->toString();

                    if ($param->flags !== 0) {
                        $properties[$param->var->name] = $param->type->toString();
                    }
                }
            }

            foreach ($finder->findInstanceOf($method->stmts ?? [], Node\Expr\MethodCall::class) as $call) {
                if (! $call->name instanceof Node\Identifier) {
                    continue;
                }

                $type = match (true) {
                    $call->var instanceof Node\Expr\Variable && is_string($call->var->name) => $parameters[$call->var->name] ?? null,
                    $call->var instanceof Node\Expr\PropertyFetch && $call->var->var instanceof Node\Expr\Variable && $call->var->var->name === 'this' && $call->var->name instanceof Node\Identifier => $properties[$call->var->name->toString()] ?? null,
                    default => null,
                };

                if ($type !== null) {
                    $calls[] = [ltrim($type, '\\'), $call->name->toString()];
                }
            }
        }

        return $calls;
    }

    private function extendsVerified(Node\Stmt\Class_ $class): bool
    {
        return $class->extends !== null && in_array(ltrim($class->extends->toString(), '\\'), self::VERIFIED_CONTROLLERS, true);
    }

    /** Whether the node's own source (a class or one method) names a verifier. */
    private function names(string $text, Node $node): bool
    {
        $source = substr($text, $node->getStartFilePos(), $node->getEndFilePos() - $node->getStartFilePos() + 1);

        foreach (self::VERIFIES as $marker) {
            if (str_contains($source, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function path(string $class): ?string
    {
        foreach ($this->config->classRoots as $root => $namespace) {
            $prefix = rtrim($namespace, '\\').'\\';

            if (str_starts_with($class, $prefix)) {
                $path = rtrim($root, '/').'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

                if (is_file($this->config->base.'/'.$path)) {
                    return $path;
                }
            }
        }

        return null;
    }

    private function text(string $class): string
    {
        $path = $this->path($class);

        return $path === null ? '' : (string) file_get_contents($this->config->base.'/'.$path);
    }

    private function classNode(string $class): ?Node\Stmt\Class_
    {
        $path = $this->path($class);

        if ($path === null) {
            return null;
        }

        foreach ((new NodeFinder)->findInstanceOf($this->source->nodes($path), Node\Stmt\Class_::class) as $node) {
            if ($node->namespacedName?->toString() === $class) {
                return $node;
            }
        }

        return null;
    }

    /** @return array<string, bool> webhook name => whether config/webhook-client.php gives it a signature validator */
    private function webhookConfigs(): array
    {
        $file = $this->config->base.'/config/webhook-client.php';
        if (! is_file($file)) {
            return [];
        }
        $configured = [];
        foreach ((new NodeFinder)->findInstanceOf($this->source->nodes('config/webhook-client.php'), Node\Expr\Array_::class) as $array) {
            $name = null;
            $validator = false;
            foreach ($array->items as $item) {
                if ($item?->key instanceof Node\Scalar\String_ && $item->key->value === 'name' && $item->value instanceof Node\Scalar\String_) {
                    $name = $item->value->value;
                }
                if ($item?->key instanceof Node\Scalar\String_ && $item->key->value === 'signature_validator') {
                    $validator = true;
                }
            }
            if ($name !== null) {
                $configured[$name] = $validator;
            }
        }

        return $configured;
    }
}
