<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Tey\Standards\Configuration;
use Tey\Standards\Source;

/**
 * [tenant.scoped-exists] An `exists` rule on a workspace record is scoped to the current workspace. In a FormRequest, or an MCP tool that validates
 * the same way, `Rule::exists(Model::class)` (or `new Exists(...)`, or the string `exists:<table>`) on a tenant-scoped model or table accepts another
 * workspace's id. Without row-level security the write then fails on the composite foreign key with a 500, so the difference between 422 and 500
 * tells a caller which ids exist elsewhere. Scoped means chained with `->where('<tenant key>', ...)` (or a `where` closure) on the rule, or a string rule
 * that names the tenant key (`exists:<table>,id,workspace_id,<id>`).
 *
 * Tenant-scoped models and tables are the ones the `tenant-aware` rule detects (traits, scopes, $fillable, migrations, Configuration::$tenantModels and
 * $tenantKeys). Lexical; advisory until an app lists the rule in Configuration::$optionalRules.
 */
final class ScopedExists
{
    public function __construct(private readonly Configuration $config, private readonly Source $source) {}

    /** @return list<array{target: string, check: string, line: int, hint: string}> */
    public function violations(): array
    {
        $scope = (new TenantAware($this->config, $this->source))->scope();
        if ($scope['models'] === [] && $scope['tables'] === []) {
            return [];
        }
        $finder = new NodeFinder;
        $violations = [];
        foreach ($this->source->files($this->config->sourceRoots) as $path => $text) {
            foreach ($finder->findInstanceOf($this->source->nodes($path), Node\Stmt\Class_::class) as $class) {
                if (! $this->validates($class)) {
                    continue;
                }
                foreach ($finder->find($class->stmts, fn (Node $node): bool => $this->isExists($node)) as $rule) {
                    $target = $this->target($rule);
                    if ($target === null || ! $this->tenantTarget($target, $scope) || $this->scoped($rule)) {
                        continue;
                    }
                    $violations[] = $this->violation($path, $rule->getStartLine(), $target);
                }
                foreach ($finder->findInstanceOf($class->stmts, Node\Scalar\String_::class) as $string) {
                    foreach (explode('|', $string->value) as $part) {
                        if (preg_match('/\Aexists:([^,|]+)((?:,[^,|]*)*)\z/', trim($part), $match) !== 1) {
                            continue;
                        }
                        $params = array_slice(explode(',', $match[2]), 1);
                        // exists:table,column,where_column,value...: scoped when a where column is a tenant key.
                        if (array_intersect(array_slice($params, 1), $this->config->tenantKeys) !== []) {
                            continue;
                        }
                        if ($this->tenantTarget(ltrim($match[1], '\\'), $scope)) {
                            $violations[] = $this->violation($path, $string->getStartLine(), $match[1]);
                        }
                    }
                }
            }
        }

        return $violations;
    }

    /** A FormRequest, or an MCP tool (laravel/mcp's Tool), by its ancestry as written. */
    private function validates(Node\Stmt\Class_ $class): bool
    {
        $parent = $class->extends?->toString() ?? '';

        return str_ends_with($parent, 'FormRequest') || $parent === 'Laravel\Mcp\Server\Tool' || str_ends_with($parent, '\Tool');
    }

    private function isExists(Node $node): bool
    {
        if ($node instanceof Node\Expr\StaticCall) {
            return $node->class instanceof Node\Name && in_array($node->class->toString(), ['Illuminate\Validation\Rule', 'Rule'], true)
                && $node->name instanceof Node\Identifier && $node->name->toString() === 'exists';
        }

        return $node instanceof Node\Expr\New_ && $node->class instanceof Node\Name && in_array($node->class->toString(), ['Illuminate\Validation\Rules\Exists', 'Exists'], true);
    }

    /** The model class or table the rule checks, when it is written as a literal. */
    private function target(Node\Expr\StaticCall|Node\Expr\New_ $rule): ?string
    {
        $first = $rule->getArgs()[0]->value ?? null;
        if ($first instanceof Node\Expr\ClassConstFetch && $first->class instanceof Node\Name && $first->name instanceof Node\Identifier && $first->name->toString() === 'class') {
            return $first->class->toString();
        }

        return $first instanceof Node\Scalar\String_ ? ltrim($first->value, '\\') : null;
    }

    /** @param  array{models: array<string, string>, tables: array<string, true>}  $scope */
    private function tenantTarget(string $target, array $scope): bool
    {
        return isset($scope['models'][$target]) || isset($scope['tables'][$target]);
    }

    /** Chained, on the rule itself, with a where on a tenant key or a where closure. */
    private function scoped(Node $rule): bool
    {
        $node = $rule;
        while (($parent = $node->getAttribute('parent')) instanceof Node\Expr\MethodCall && $parent->var === $node) {
            if ($parent->name instanceof Node\Identifier && in_array($parent->name->toString(), ['where', 'whereIn'], true)) {
                $first = $parent->getArgs()[0]->value ?? null;
                if (($first instanceof Node\Scalar\String_ && in_array($first->value, $this->config->tenantKeys, true)) || $first instanceof Node\Expr\Closure || $first instanceof Node\Expr\ArrowFunction) {
                    return true;
                }
            }
            $node = $parent;
        }

        return false;
    }

    /** @return array{target: string, check: string, line: int, hint: string} */
    private function violation(string $path, int $line, string $target): array
    {
        return ['target' => $path, 'line' => $line, 'check' => 'exists rule on workspace records ('.$target.') is not scoped to the current workspace',
            'hint' => 'Another workspace\'s id passes validation, then the composite foreign key answers 500: an existence oracle. Chain ->where(\'workspace_id\', (int) (Workspace::current()?->getKey() ?? 0)).'];
    }
}
