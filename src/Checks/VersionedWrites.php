<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use Illuminate\Support\Str;
use PhpParser\Node;
use PhpParser\NodeFinder;
use Tey\Standards\Configuration;
use Tey\Standards\Source;

/**
 * Rule `versioned-writes`: a query-builder write on a versioned model's table bumps its version. A versioned (stale-write guarded) model bumps
 * `version` through its model events, but a builder `update()`, `upsert()`, `increment()` or `decrement()` (on `Model::where(…)`, `Model::query()`,
 * or `DB::table('<its table>')`) skips those events, so a stale guard silently passes. Each such write must set the version column, as a value or
 * `DB::raw('version + 1')`; an increment of the version column itself counts.
 *
 * A model is versioned when it uses a trait named Versioned, HasVersion or OptimisticLock (or one of Configuration::$versionedModels' traits or
 * classes). An Eloquent instance write (`$model->update([...])`, `Model::find($id)->update(...)`) runs the events and is not reported. Static and a
 * heuristic: a write reached through another class or a variable holding the builder is not seen. A deliberate exception goes in the allowlist.
 */
final class VersionedWrites
{
    private const WRITES = ['update', 'upsert', 'increment', 'decrement', 'incrementEach', 'decrementEach', 'updateOrInsert'];

    /** Static entry points that return the model itself, so a write after them is an instance write that runs the model events. */
    private const INSTANCES = ['find', 'findOrFail', 'findOr', 'first', 'firstOrFail', 'firstOr', 'sole', 'firstWhere', 'findSole', 'make', 'create', 'forceCreate', 'firstOrNew', 'firstOrCreate', 'updateOrCreate'];

    public function __construct(private readonly Configuration $config, private readonly Source $source) {}

    /** @return list<array{target: string, check: string, line: int, hint: string}> */
    public function violations(): array
    {
        $finder = new NodeFinder;
        $classes = [];

        foreach ($this->source->files($this->config->sourceRoots) as $path => $text) {
            foreach ($finder->findInstanceOf($this->source->nodes($path), Node\Stmt\Class_::class) as $class) {
                if ($class->namespacedName !== null) {
                    $classes[$class->namespacedName->toString()] = $class;
                }
            }
        }

        $models = [];

        foreach ($classes as $name => $class) {
            if ($this->versioned($class, $classes)) {
                $models[$name] = $this->table($class);
            }
        }

        if ($models === []) {
            return [];
        }

        $tables = array_flip($models);
        $violations = [];

        foreach ($this->source->files($this->config->sourceRoots) as $path => $text) {
            foreach ($finder->find($this->source->nodes($path), fn (Node $node): bool => ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall) && $node->name instanceof Node\Identifier && in_array($node->name->toString(), self::WRITES, true)) as $call) {
                // A write straight on the model (Document::upsert(…)) forwards to a fresh builder, as Model::query()->upsert(…) does.
                $model = $call instanceof Node\Expr\StaticCall
                    ? ($call->class instanceof Node\Name && isset($models[ltrim($call->class->toString(), '\\')]) ? ltrim($call->class->toString(), '\\') : null)
                    : $this->builderModel($call->var, $models, $tables);

                if ($model === null || $this->bumps($call)) {
                    continue;
                }

                $method = $call->name->toString();
                $violations[] = [
                    'target' => $path,
                    'check' => $model.' builder '.$method.'() skips the '.$this->config->versionColumn.' bump',
                    'line' => $call->getStartLine(),
                    'hint' => 'A query-builder '.$method.'() bypasses the model events that bump '.$this->config->versionColumn.', so a stale-write guard silently passes. Set it in the write (\''.$this->config->versionColumn.'\' => DB::raw(\''.$this->config->versionColumn.' + 1\')), write through the model instance, or allowlist it with a reason.',
                ];
            }
        }

        return array_values(array_unique($violations, SORT_REGULAR));
    }

    /** @param  array<string, Node\Stmt\Class_>  $classes */
    private function versioned(Node\Stmt\Class_ $class, array $classes): bool
    {
        for ($node = $class, $depth = 0; $node !== null && $depth < 20; $depth++) {
            if (in_array(ltrim((string) $node->namespacedName?->toString(), '\\'), $this->config->versionedModels, true)) {
                return true;
            }

            foreach ($node->getTraitUses() as $use) {
                foreach ($use->traits as $trait) {
                    if (in_array(ltrim($trait->toString(), '\\'), $this->config->versionedModels, true) || preg_match('/^(Versioned|HasVersion|HasVersions|OptimisticLock|OptimisticLocking)$/', $trait->getLast()) === 1) {
                        return true;
                    }
                }
            }

            $parent = $node->extends === null ? null : ltrim($node->extends->toString(), '\\');

            if ($parent !== null && in_array($parent, $this->config->versionedModels, true)) {
                return true;
            }

            $node = $parent === null ? null : ($classes[$parent] ?? null);
        }

        return false;
    }

    private function table(Node\Stmt\Class_ $class): string
    {
        foreach ($class->getProperties() as $property) {
            foreach ($property->props as $prop) {
                if ($prop->name->toString() === 'table' && $prop->default instanceof Node\Scalar\String_) {
                    return $prop->default->value;
                }
            }
        }

        return Str::snake(Str::pluralStudly((string) $class->name));
    }

    /**
     * The versioned model a builder chain writes to: `Model::where(…)->…`, `Model::query()->…`, or `DB::table('<its table>')->…`. Null when the
     * chain starts anywhere else, or passes through a static call that returns the model itself (an instance write runs the events).
     *
     * @param  array<string, string>  $models  model => table
     * @param  array<string, string>  $tables  table => model
     */
    private function builderModel(Node\Expr $expr, array $models, array $tables): ?string
    {
        while ($expr instanceof Node\Expr\MethodCall || $expr instanceof Node\Expr\NullsafeMethodCall) {
            if ($expr->name instanceof Node\Identifier && in_array($expr->name->toString(), ['first', 'firstOrFail', 'sole', 'find', 'findOrFail'], true)) {
                return null;
            }

            $expr = $expr->var;
        }

        if (! $expr instanceof Node\Expr\StaticCall || ! $expr->class instanceof Node\Name || ! $expr->name instanceof Node\Identifier) {
            return null;
        }

        $class = ltrim($expr->class->toString(), '\\');
        $method = $expr->name->toString();

        if (isset($models[$class])) {
            return in_array($method, self::INSTANCES, true) ? null : $class;
        }

        if (in_array($class, ['Illuminate\Support\Facades\DB', 'DB'], true) && $method === 'table' && ($expr->getArgs()[0]->value ?? null) instanceof Node\Scalar\String_) {
            return $tables[$expr->getArgs()[0]->value->value] ?? null;
        }

        return null;
    }

    /** Whether the write sets the version column (any value, DB::raw included), or increments it. */
    private function bumps(Node\Expr\MethodCall|Node\Expr\StaticCall $call): bool
    {
        $column = $this->config->versionColumn;
        $arguments = $call->getArgs();
        $method = $call->name instanceof Node\Identifier ? $call->name->toString() : '';
        $sets = fn (?Node\Expr $value): bool => $value instanceof Node\Expr\Array_ && (new NodeFinder)->findFirst([$value], fn (Node $node): bool => ($node instanceof Node\ArrayItem && $node->key instanceof Node\Scalar\String_ && $node->key->value === $column)
            || ($node instanceof Node\ArrayItem && $node->key === null && $node->value instanceof Node\Scalar\String_ && $node->value->value === $column)) !== null;

        return match ($method) {
            // upsert($values, $uniqueBy, $update): the updated columns (or, without them, every column in the values) must include it.
            'upsert' => isset($arguments[2]) ? $sets($arguments[2]->value) : $sets($arguments[0]->value ?? null),
            'increment', 'decrement' => (($arguments[0]->value ?? null) instanceof Node\Scalar\String_ && $arguments[0]->value->value === $column) || $sets($arguments[2]->value ?? null),
            'incrementEach', 'decrementEach' => $sets($arguments[0]->value ?? null) || $sets($arguments[1]->value ?? null),
            'updateOrInsert' => $sets($arguments[1]->value ?? null),
            default => $sets($arguments[0]->value ?? null),
        };
    }
}
