<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Tey\Standards\Configuration;
use Tey\Standards\Source;

/**
 * Rule `live.broadcast-now`: the notice a live prop listens for is ShouldBroadcastNow. A queued broadcast (ShouldBroadcast) needs an
 * always-on worker to reach the socket; on a scale-to-zero queue it wakes the queue on every event, or never arrives at all.
 *
 * Read from the source roots: `Inertia::live(..., on: ...)`, `new Inertia\LiveProp(..., on: ...)` and tey/kit's
 * `LiveProps::on($props, $keys, $notice)`. The notice is `new Event(...)`, `Event::class`, or a local variable assigned `new Event(...)` in
 * the same function. A literal broadcast name with no namespace (a non-Laravel producer) is not checked. A notice the rule cannot
 * resolve (a parameter, a method call) is reported, so the rule never passes on something it didn't read; give it a local `new`.
 */
final readonly class LiveBroadcastNow
{
    private const string NOW = 'Illuminate\Contracts\Broadcasting\ShouldBroadcastNow';

    /** class => [method, notice argument name, notice position] */
    private const array CALLS = [
        'Inertia\Inertia' => ['live', 'on', 1],
        'Tey\Kit\Features\Core\Live\LiveProps' => ['on', 'notice', 2],
    ];

    public function __construct(private Configuration $config, private Source $source) {}

    /** @return list<array{target: string, check: string}> */
    public function violations(): array
    {
        $violations = [];
        $declared = null;
        foreach ($this->source->files($this->config->sourceRoots) as $path => $text) {
            if (! str_contains($text, 'live') && ! str_contains($text, 'LiveProp')) {
                continue;
            }
            $finder = new NodeFinder;
            foreach ($finder->find($this->source->nodes($path), fn (Node $node): bool => $node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\New_) as $call) {
                $notice = $this->notice($call);
                if ($notice === false) {
                    continue;
                }
                $target = $path.':'.$call->getStartLine();
                $class = $notice === null ? null : $this->resolve($notice, $call);
                if ($class === '') {
                    continue;
                }
                if ($class === null) {
                    $violations[] = ['target' => $target, 'check' => 'a live prop notice the rule cannot read: pass `new Event(...)` or a local variable assigned one, so it can check the event is ShouldBroadcastNow'];

                    continue;
                }
                $declared ??= $this->source->declared();
                $now = $this->broadcastsNow($class, $declared);
                if ($now === null) {
                    $violations[] = ['target' => $target, 'check' => "the live prop notice {$class} could not be found to check it is ShouldBroadcastNow"];
                } elseif (! $now) {
                    $violations[] = ['target' => $target, 'check' => "the live prop notice {$class} is not ShouldBroadcastNow: a queued broadcast needs an always-on worker, so implement ShouldBroadcastNow"];
                }
            }
        }

        return $violations;
    }

    /** The notice argument of a live call; null when the call has none; false when the node is not a live call. */
    private function notice(Node\Expr\StaticCall|Node\Expr\New_ $call): Node\Expr|false|null
    {
        if ($call instanceof Node\Expr\New_) {
            if (! $call->class instanceof Node\Name || ltrim($call->class->toString(), '\\') !== 'Inertia\LiveProp') {
                return false;
            }
            [$name, $position] = ['on', 1];
        } else {
            if (! $call->class instanceof Node\Name || ! $call->name instanceof Node\Identifier || $call->isFirstClassCallable()) {
                return false;
            }
            $spec = self::CALLS[ltrim($call->class->toString(), '\\')] ?? null;
            if ($spec === null || strtolower($call->name->toString()) !== $spec[0]) {
                return false;
            }
            [, $name, $position] = $spec;
        }
        $positional = 0;
        foreach ($call->getArgs() as $arg) {
            if ($arg->name !== null) {
                if ($arg->name->toString() === $name) {
                    return $arg->value;
                }

                continue;
            }
            if ($positional++ === $position) {
                return $arg->value;
            }
        }

        return null;
    }

    /** The notice's class; '' for a literal broadcast name (not checked); null when it cannot be read. */
    private function resolve(Node\Expr $notice, Node $call): ?string
    {
        if ($notice instanceof Node\Expr\New_) {
            return $notice->class instanceof Node\Name ? ltrim($notice->class->toString(), '\\') : null;
        }
        if ($notice instanceof Node\Expr\ClassConstFetch && $notice->class instanceof Node\Name && $notice->name instanceof Node\Identifier && strtolower($notice->name->toString()) === 'class') {
            return ltrim($notice->class->toString(), '\\');
        }
        if ($notice instanceof Node\Scalar\String_) {
            return str_contains($notice->value, '\\') ? ltrim($notice->value, '\\') : '';
        }
        if ($notice instanceof Node\Expr\Variable && is_string($notice->name)) {
            $scope = $call->getAttribute('parent');
            while ($scope !== null && ! $scope instanceof Node\FunctionLike) {
                $scope = $scope->getAttribute('parent');
            }
            if ($scope === null) {
                return null;
            }
            $classes = [];
            foreach ((new NodeFinder)->findInstanceOf($scope->getStmts() ?? [], Node\Expr\Assign::class) as $assign) {
                if ($assign->var instanceof Node\Expr\Variable && $assign->var->name === $notice->name) {
                    $classes[] = $assign->expr instanceof Node\Expr\New_ && $assign->expr->class instanceof Node\Name ? ltrim($assign->expr->class->toString(), '\\') : null;
                }
            }
            $classes = array_unique($classes);

            // One assignment, of a `new`: anything else is not read.
            return count($classes) === 1 ? $classes[0] : null;
        }

        return null;
    }

    /**
     * Whether a class implements ShouldBroadcastNow: from the autoloader when it loads, otherwise read from the app's own declarations up its
     * parents (a direct `implements ShouldBroadcastNow` on the class or a parent).
     *
     * @param  array<string, string>  $declared
     */
    private function broadcastsNow(string $class, array $declared): ?bool
    {
        // Loaded, the autoloader is the whole answer: interfaces that extend ShouldBroadcastNow, traits and parents included.
        if (class_exists($class)) {
            return in_array(self::NOW, class_implements($class) ?: [], true);
        }
        $seen = [];
        while (isset($declared[$class]) && ! isset($seen[$class])) {
            $seen[$class] = true;
            $node = null;
            foreach ((new NodeFinder)->findInstanceOf($this->source->nodes($declared[$class]), Node\Stmt\Class_::class) as $candidate) {
                if (isset($candidate->namespacedName) && $candidate->namespacedName->toString() === $class) {
                    $node = $candidate;
                }
            }
            if ($node === null) {
                return null;
            }
            foreach ($node->implements as $interface) {
                if (ltrim($interface->toString(), '\\') === self::NOW) {
                    return true;
                }
            }
            if ($node->extends === null) {
                return false;
            }
            $class = ltrim($node->extends->toString(), '\\');
        }
        if (class_exists($class)) {
            return in_array(self::NOW, class_implements($class) ?: [], true);
        }

        return null;
    }
}
