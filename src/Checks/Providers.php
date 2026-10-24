<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use PhpParser\Node;
use PhpParser\NodeFinder;
use ReflectionClass;
use Tey\Standards\Configuration;
use Tey\Standards\Source;
use Throwable;

final class Providers
{
    public function __construct(private readonly Configuration $config, private readonly Source $source) {}

    /** @return list<array{target: string, check: string}> */
    public function violations(): array
    {
        $path = 'bootstrap/providers.php';
        if (! is_file($this->config->base.'/'.$path)) {
            return [];
        }
        $manifest = $this->json('composer.json');
        $lock = $this->json('composer.lock');
        $dev = [];
        $runtime = $this->prefixes($manifest['autoload'] ?? []);
        foreach ($lock['packages-dev'] ?? [] as $package) {
            $dev = [...$dev, ...$this->prefixes($package['autoload'] ?? [])];
        }
        foreach ($lock['packages'] ?? [] as $package) {
            $runtime = [...$runtime, ...$this->prefixes($package['autoload'] ?? [])];
        }
        $dev = [...$dev, ...$this->prefixes($manifest['autoload-dev'] ?? [])];
        $violations = [];
        foreach ($this->returns($this->source->nodes($path), []) as [$return, $guarded]) {
            if (! $this->listable($return->expr)) {
                $violations[] = ['target' => $path, 'check' => 'provider list must be a literal array'];

                continue;
            }
            foreach ($this->entries($return->expr, $guarded, false) as $item) {
                $value = $item->value;
                $class = $value instanceof Node\Expr\ClassConstFetch && $value->class instanceof Node\Name && $value->name instanceof Node\Identifier && strtolower($value->name->toString()) === 'class'
                    ? $value->class->toString() : ($value instanceof Node\Scalar\String_ ? ltrim($value->value, '\\') : null);
                if ($class === null || $item->unpack) {
                    $violations[] = ['target' => $path, 'check' => 'provider entry must be a literal class'];

                    continue;
                }
                $devPrefix = $this->longestPrefix($class, $dev);
                $runtimePrefix = $this->longestPrefix($class, $runtime);
                $devPackage = $this->fromDevPackage($class, $manifest, $lock);
                if (($devPrefix > 0 && $devPrefix > $runtimePrefix) || $devPackage) {
                    $violations[] = ['target' => $path, 'check' => $class.' belongs to require-dev'];

                    continue;
                }
                try {
                    $exists = class_exists($class);
                } catch (Throwable) {
                    $exists = false;
                }
                if (! $exists) {
                    $violations[] = ['target' => $path, 'check' => $class.' is not autoloadable'];
                }
            }
        }

        return array_values(array_unique($violations, SORT_REGULAR));
    }

    /**
     * Returns with the classes an enclosing `if (class_exists(X::class))` guards.
     *
     * @param  array<Node>  $nodes
     * @param  list<string>  $guarded
     * @return list<array{0: Node\Stmt\Return_, 1: list<string>}>
     */
    private function returns(array $nodes, array $guarded): array
    {
        $found = [];
        foreach ($nodes as $node) {
            if ($node instanceof Node\Stmt\Return_) {
                $found[] = [$node, $guarded];
            } elseif ($node instanceof Node\Stmt\If_) {
                $found = [...$found, ...$this->returns($node->stmts, [...$guarded, ...$this->guards($node->cond)])];
                foreach ($node->elseifs as $elseif) {
                    $found = [...$found, ...$this->returns($elseif->stmts, [...$guarded, ...$this->guards($elseif->cond)])];
                }
                $found = [...$found, ...$this->returns($node->else->stmts ?? [], $guarded)];
            } elseif ($node instanceof Node\Stmt\Namespace_ || $node instanceof Node\Stmt\Block) {
                $found = [...$found, ...$this->returns($node->stmts, $guarded)];
            } else {
                foreach ((new NodeFinder)->findInstanceOf([$node], Node\Stmt\Return_::class) as $return) {
                    $found[] = [$return, $guarded];
                }
            }
        }

        return $found;
    }

    /** @return list<string> classes named by `class_exists(X::class)` calls in a condition */
    private function guards(Node $condition): array
    {
        $classes = [];
        foreach ((new NodeFinder)->findInstanceOf([$condition], Node\Expr\FuncCall::class) as $call) {
            $argument = $call->args[0] ?? null;
            if ($call->name instanceof Node\Name && strtolower($call->name->toString()) === 'class_exists' && $argument instanceof Node\Arg
                && $argument->value instanceof Node\Expr\ClassConstFetch && $argument->value->class instanceof Node\Name) {
                $classes[] = $argument->value->class->toString();
            }
        }

        return $classes;
    }

    private function listable(?Node\Expr $expr): bool
    {
        return $expr instanceof Node\Expr\Array_ || $this->filtered($expr) !== null;
    }

    /** The literal array of `array_filter([...], class_exists(...))`, in any callable spelling. */
    private function filtered(?Node\Expr $expr): ?Node\Expr\Array_
    {
        if (! $expr instanceof Node\Expr\FuncCall || ! $expr->name instanceof Node\Name || strtolower($expr->name->toString()) !== 'array_filter') {
            return null;
        }
        $list = $expr->args[0] ?? null;
        $callback = $expr->args[1] ?? null;
        if (! $list instanceof Node\Arg || ! $list->value instanceof Node\Expr\Array_ || ! $callback instanceof Node\Arg) {
            return null;
        }
        $callable = $callback->value;
        $named = $callable instanceof Node\Scalar\String_ && strtolower(ltrim($callable->value, '\\')) === 'class_exists';
        $firstClass = $callable instanceof Node\Expr\FuncCall && $callable->name instanceof Node\Name && strtolower($callable->name->toString()) === 'class_exists' && $callable->isFirstClassCallable();
        $closure = ($callable instanceof Node\Expr\ArrowFunction || $callable instanceof Node\Expr\Closure) && (new NodeFinder)->findFirst([$callable], fn (Node $node): bool => $node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name && strtolower($node->name->toString()) === 'class_exists') !== null;

        return $named || $firstClass || $closure ? $list->value : null;
    }

    /**
     * Entries to check; ones guarded by class_exists are left out.
     *
     * @param  list<string>  $guarded
     * @return list<Node\Expr\ArrayItem>
     */
    private function entries(Node\Expr $expr, array $guarded, bool $all): array
    {
        if (($list = $this->filtered($expr)) !== null) {
            return $this->entries($list, $guarded, true);
        }
        if (! $expr instanceof Node\Expr\Array_) {
            return [new Node\Expr\ArrayItem($expr)];
        }
        $entries = [];
        foreach ($expr->items as $item) {
            $value = $item->value;
            if ($item->unpack && $value instanceof Node\Expr\Ternary) {
                $entries = [...$entries, ...$this->ternary($value, $guarded, $all)];
            } elseif ($item->unpack && $value instanceof Node\Expr\Array_) {
                $entries = [...$entries, ...$this->entries($value, $guarded, $all)];
            } elseif ($value instanceof Node\Expr\Ternary) {
                $entries = [...$entries, ...$this->ternary($value, $guarded, $all)];
            } elseif (! $all && ! $this->isGuarded($value, $guarded)) {
                $entries[] = $item;
            }
        }

        return $entries;
    }

    /**
     * @param  list<string>  $guarded
     * @return list<Node\Expr\ArrayItem>
     */
    private function ternary(Node\Expr\Ternary $ternary, array $guarded, bool $all): array
    {
        $branches = [];
        foreach ([[$ternary->if ?? $ternary->cond, [...$guarded, ...$this->guards($ternary->cond)]], [$ternary->else, $guarded]] as [$branch, $scope]) {
            if ($branch instanceof Node\Expr\ConstFetch && strtolower($branch->name->toString()) === 'null') {
                continue;
            }
            $branches = [...$branches, ...($branch instanceof Node\Expr\Array_ ? $this->entries($branch, $scope, $all) : ($all || $this->isGuarded($branch, $scope) ? [] : [new Node\Expr\ArrayItem($branch)]))];
        }

        return $branches;
    }

    /** @param list<string> $guarded */
    private function isGuarded(Node\Expr $value, array $guarded): bool
    {
        return $value instanceof Node\Expr\ClassConstFetch && $value->class instanceof Node\Name && in_array($value->class->toString(), $guarded, true);
    }

    /** @return array<string, mixed> */
    private function json(string $path): array
    {
        return is_file($this->config->base.'/'.$path) ? json_decode((string) file_get_contents($this->config->base.'/'.$path), true, flags: JSON_THROW_ON_ERROR) : [];
    }

    /** @param array<string, mixed> $autoload
     * @return list<string>
     */
    private function prefixes(array $autoload): array
    {
        return array_values(array_filter([...array_keys($autoload['psr-4'] ?? []), ...array_keys($autoload['psr-0'] ?? [])], is_string(...)));
    }

    /** @param list<string> $prefixes */
    private function longestPrefix(string $class, array $prefixes): int
    {
        $length = 0;
        foreach ($prefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($class, $prefix)) {
                $length = max($length, strlen($prefix));
            }
        }

        return $length;
    }

    /** Classmap and files autoloaders have no namespace metadata. Inspect the resolved file without instantiating the provider.
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $lock
     */
    private function fromDevPackage(string $class, array $manifest, array $lock): bool
    {
        try {
            if (! class_exists($class)) {
                return false;
            }
            $file = (new ReflectionClass($class))->getFileName();
        } catch (Throwable) {
            return false;
        }
        if ($file === false) {
            return false;
        }
        $vendor = $this->config->base.'/'.($manifest['config']['vendor-dir'] ?? 'vendor');
        foreach ($lock['packages-dev'] ?? [] as $package) {
            $directory = realpath($vendor.'/'.$package['name']);
            if ($directory !== false && str_starts_with($file, $directory.DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }
}
