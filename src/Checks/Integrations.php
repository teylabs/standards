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
 *   controller that runs WebhookProcessor) with a configured signature validator, Cashier's webhook controller, or a controller whose source verifies a
 *   signature (hash_equals, a SignatureValidator, a verify call, Stripe's constructEvent).
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

    private function verifies(string $controller): bool
    {
        if (in_array($controller, self::VERIFIED_CONTROLLERS, true)) {
            return true;
        }
        foreach ($this->config->classRoots as $root => $namespace) {
            $prefix = rtrim($namespace, '\\').'\\';
            if (str_starts_with($controller, $prefix)) {
                $file = $this->config->base.'/'.rtrim($root, '/').'/'.str_replace('\\', '/', substr($controller, strlen($prefix))).'.php';
                if (is_file($file)) {
                    $source = (string) file_get_contents($file);
                    foreach (self::VERIFIES as $marker) {
                        if (str_contains($source, $marker)) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
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
