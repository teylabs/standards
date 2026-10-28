<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Tey\Standards\Configuration;
use Tey\Standards\Source;

/**
 * Rule `tenant-aware`: work that touches tenant-scoped models runs inside a tenant, the spatie/laravel-multitenancy way, and only that way.
 *
 * - An artisan command (extending Illuminate\Console\Command) that references a tenant-scoped model uses Spatie's
 *   Spatie\Multitenancy\Commands\Concerns\TenantAware trait, so it runs once per tenant (--tenant=*).
 * - A queued job (ShouldQueue) that references one is tenant-aware as the app's config/multitenancy.php decides: it implements
 *   Spatie\Multitenancy\Jobs\TenantAware, or is listed in `tenant_aware_jobs`, or queues are tenant-aware by default
 *   (`queues_are_tenant_aware_by_default`, true unless set) and it is neither listed in `not_tenant_aware_jobs` nor implements
 *   Spatie\Multitenancy\Jobs\NotTenantAware. Otherwise the job runs outside any tenant and silently finds nothing.
 * - A tenant-scoped model that is Prunable or MassPrunable has its prunable() bypass the scope (withoutGlobalScopes), so
 *   `model:prune` (which runs outside any tenant) is landlord or cross-tenant housekeeping by design; otherwise it prunes nothing.
 * - Anywhere: `$tenant->execute(fn () => dispatch(...))` loses the tenant. A PendingDispatch queues when it is destroyed, after
 *   execute() has restored the previous tenant. Use a block closure, or dispatch a resolved job through Bus::dispatch.
 *
 * A model is tenant-scoped when it uses one of Configuration::$tenantModels' traits, or is or extends one of its classes. Deliberate
 * cross-tenant admin commands are declared in the allowlist (a reason and an expiry), never by a marker. Active only when the app
 * requires spatie/laravel-multitenancy or configures $tenantModels beyond the default.
 */
final class TenantAware
{
    private const COMMAND = 'Illuminate\Console\Command';

    private const SHOULD_QUEUE = 'Illuminate\Contracts\Queue\ShouldQueue';

    private const COMMAND_TRAIT = 'Spatie\Multitenancy\Commands\Concerns\TenantAware';

    private const JOB_TENANT_AWARE = 'Spatie\Multitenancy\Jobs\TenantAware';

    private const JOB_NOT_TENANT_AWARE = 'Spatie\Multitenancy\Jobs\NotTenantAware';

    private const PRUNABLE = ['Illuminate\Database\Eloquent\Prunable', 'Illuminate\Database\Eloquent\MassPrunable'];

    public const DEFAULT_MODELS = ['Spatie\Multitenancy\Models\Concerns\UsesTenantConnection'];

    public function __construct(private readonly Configuration $config, private readonly Source $source) {}

    /** @return list<array{target: string, check: string, line?: int, hint?: string}> */
    public function violations(): array
    {
        if (! $this->active()) {
            return [];
        }

        $finder = new NodeFinder;
        $classes = [];

        foreach ($this->source->files($this->config->sourceRoots) as $path => $text) {
            foreach ($finder->findInstanceOf($this->source->nodes($path), Node\Stmt\Class_::class) as $class) {
                if ($class->namespacedName !== null) {
                    $classes[$class->namespacedName->toString()] = [$path, $class];
                }
            }
        }

        $tenantModels = $this->tenantModels($classes);
        $queue = $this->queueConfig();
        $violations = [];

        foreach ($classes as $name => [$path, $class]) {
            $kind = $this->kind($class, $classes);

            if ($kind === null && ! isset($tenantModels[$name])) {
                continue;
            }

            if (isset($tenantModels[$name])) {
                if ($this->usesAny($class, self::PRUNABLE, $classes) && ! $this->bypassesScope($class)) {
                    $violations[] = ['target' => $path, 'check' => $name.' is a tenant-scoped model that model:prune prunes outside any tenant', 'line' => $class->getStartLine(),
                        'hint' => 'model:prune runs outside any tenant, so this prunes nothing (or the wrong connection). Make prunable() bypass the tenant scope (withoutGlobalScopes) if pruning is cross-tenant by design, or prune per tenant from a TenantAware command.'];
                }

                continue;
            }

            $touch = $this->firstTouch($class, $tenantModels);

            if ($touch === null) {
                continue;
            }

            [$model, $line] = $touch;

            if ($kind === 'command' && ! $this->usesAny($class, [self::COMMAND_TRAIT], $classes)) {
                $violations[] = ['target' => $path, 'check' => $name.' touches tenant-scoped '.$model.' without TenantAware', 'line' => $line,
                    'hint' => 'Use Spatie\Multitenancy\Commands\Concerns\TenantAware in the command (it then runs per tenant: --tenant=*). A deliberate cross-tenant admin command goes in the allowlist with a reason and an expiry.'];
            }

            if ($kind === 'job') {
                $implements = $this->implementsAll($class, $classes);

                if (in_array(self::JOB_NOT_TENANT_AWARE, $implements, true)) {
                    $violations[] = ['target' => $path, 'check' => $name.' touches tenant-scoped '.$model.' but is NotTenantAware', 'line' => $line,
                        'hint' => 'A NotTenantAware job runs outside any tenant and finds nothing. Implement Spatie\Multitenancy\Jobs\TenantAware instead (or rely on queues_are_tenant_aware_by_default).'];
                } elseif (! in_array(self::JOB_TENANT_AWARE, $implements, true) && ! in_array($name, $queue['aware'], true) && (! $queue['default'] || in_array($name, $queue['not_aware'], true))) {
                    $violations[] = ['target' => $path, 'check' => $name.' touches tenant-scoped '.$model.' but is not tenant-aware', 'line' => $line,
                        'hint' => in_array($name, $queue['not_aware'], true)
                            ? 'It is listed in config/multitenancy.php not_tenant_aware_jobs, so it runs outside any tenant. Remove it from that list, or implement Spatie\Multitenancy\Jobs\TenantAware.'
                            : 'Queues are not tenant-aware by default here (queues_are_tenant_aware_by_default is false). Implement Spatie\Multitenancy\Jobs\TenantAware, or list it in tenant_aware_jobs.'];
                }
            }
        }

        // The gotcha, anywhere in the source: an arrow fn whose body is a bare dispatch, run inside execute().
        foreach ($this->source->files($this->config->sourceRoots) as $path => $text) {
            foreach ($finder->find($this->source->nodes($path), fn (Node $node): bool => $node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier && $node->name->toString() === 'execute') as $call) {
                foreach ($call->getArgs() as $argument) {
                    if ($argument->value instanceof Node\Expr\ArrowFunction && self::bareDispatch($argument->value->expr)) {
                        $owner = $this->owner($call);
                        $violations[] = ['target' => $path, 'check' => ($owner === null ? '' : $owner.' ').'dispatches from an arrow fn passed to execute(), which loses the tenant', 'line' => $call->getStartLine(),
                            'hint' => 'A PendingDispatch queues when it is destroyed, after execute() has restored the previous tenant. Use a block closure (function () { dispatch(...); }), or Bus::dispatch($job) / dispatch the resolved job inside it.'];
                    }
                }
            }
        }

        return array_values(array_unique($violations, SORT_REGULAR));
    }

    /** spatie/laravel-multitenancy is required, or the app named its own tenant-model traits or classes. */
    private function active(): bool
    {
        $file = $this->config->base.'/composer.json';
        $composer = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $packages = is_array($composer) ? [...array_keys($composer['require'] ?? []), ...array_keys($composer['require-dev'] ?? [])] : [];

        return in_array('spatie/laravel-multitenancy', $packages, true) || array_values($this->config->tenantModels) !== self::DEFAULT_MODELS;
    }

    /**
     * @param  array<string, array{0: string, 1: Node\Stmt\Class_}>  $classes
     * @return array<string, true>
     */
    private function tenantModels(array $classes): array
    {
        $models = [];

        foreach ($classes as $name => [, $class]) {
            if (in_array($name, $this->config->tenantModels, true) || $this->usesAny($class, $this->config->tenantModels, $classes) || array_intersect($this->ancestors($class, $classes), $this->config->tenantModels) !== []) {
                $models[$name] = true;
            }
        }

        foreach ($this->config->tenantModels as $configured) {
            if (! isset($classes[$configured]) && ! trait_exists($configured, false) && class_exists($configured, false)) {
                $models[$configured] = true;
            }
        }

        return $models;
    }

    /** @param  array<string, array{0: string, 1: Node\Stmt\Class_}>  $classes */
    private function kind(Node\Stmt\Class_ $class, array $classes): ?string
    {
        if (in_array(self::COMMAND, $this->ancestors($class, $classes), true)) {
            return 'command';
        }

        return in_array(self::SHOULD_QUEUE, $this->implementsAll($class, $classes), true) ? 'job' : null;
    }

    /**
     * The parent classes, by name, as far as the app's own source shows them.
     *
     * @param  array<string, array{0: string, 1: Node\Stmt\Class_}>  $classes
     * @return list<string>
     */
    private function ancestors(Node\Stmt\Class_ $class, array $classes): array
    {
        $names = [];

        while ($class->extends !== null && count($names) < 20) {
            $parent = ltrim($class->extends->toString(), '\\');
            $names[] = $parent;

            if (! isset($classes[$parent])) {
                break;
            }

            $class = $classes[$parent][1];
        }

        return $names;
    }

    /**
     * Interfaces it implements, its parents' included.
     *
     * @param  array<string, array{0: string, 1: Node\Stmt\Class_}>  $classes
     * @return list<string>
     */
    private function implementsAll(Node\Stmt\Class_ $class, array $classes): array
    {
        $names = array_map(fn (Node\Name $name): string => ltrim($name->toString(), '\\'), $class->implements);

        foreach ($this->ancestors($class, $classes) as $parent) {
            if (isset($classes[$parent])) {
                $names = [...$names, ...array_map(fn (Node\Name $name): string => ltrim($name->toString(), '\\'), $classes[$parent][1]->implements)];
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Whether it, or a parent in the app's source, uses one of the traits.
     *
     * @param  list<string>  $traits
     * @param  array<string, array{0: string, 1: Node\Stmt\Class_}>  $classes
     */
    private function usesAny(Node\Stmt\Class_ $class, array $traits, array $classes): bool
    {
        foreach ([$class, ...array_map(fn (string $parent): ?Node\Stmt\Class_ => $classes[$parent][1] ?? null, $this->ancestors($class, $classes))] as $node) {
            foreach ($node?->getTraitUses() ?? [] as $use) {
                foreach ($use->traits as $trait) {
                    if (in_array(ltrim($trait->toString(), '\\'), $traits, true)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * The first tenant-scoped model it names (a static call, `new`, a class constant or a type), with the line.
     *
     * @param  array<string, true>  $tenantModels
     * @return array{0: string, 1: int}|null
     */
    private function firstTouch(Node\Stmt\Class_ $class, array $tenantModels): ?array
    {
        $first = null;

        foreach ((new NodeFinder)->findInstanceOf($class->stmts, Node\Name::class) as $name) {
            $resolved = ltrim(($name->getAttribute('resolvedName') ?? $name)->toString(), '\\');

            if (isset($tenantModels[$resolved]) && ($first === null || $name->getStartLine() < $first[1])) {
                $first = [$resolved, $name->getStartLine()];
            }
        }

        return $first;
    }

    private function bypassesScope(Node\Stmt\Class_ $class): bool
    {
        $prunable = $class->getMethod('prunable');

        return $prunable !== null && (new NodeFinder)->findFirst($prunable->stmts ?? [], fn (Node $node): bool => ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall)
            && $node->name instanceof Node\Identifier && in_array($node->name->toString(), ['withoutGlobalScopes', 'withoutGlobalScope'], true)) !== null;
    }

    private static function bareDispatch(Node\Expr $expr): bool
    {
        return ($expr instanceof Node\Expr\FuncCall && $expr->name instanceof Node\Name && in_array(strtolower(ltrim($expr->name->toString(), '\\')), ['dispatch'], true))
            || ($expr instanceof Node\Expr\StaticCall && $expr->name instanceof Node\Identifier && in_array($expr->name->toString(), ['dispatch', 'dispatchIf', 'dispatchUnless'], true));
    }

    private function owner(Node $node): ?string
    {
        while (($node = $node->getAttribute('parent')) !== null) {
            if ($node instanceof Node\Stmt\ClassLike && isset($node->namespacedName)) {
                return $node->namespacedName->toString();
            }
        }

        return null;
    }

    /**
     * The app's config/multitenancy.php, read statically: whether queues are tenant-aware by default (Spatie's default: true), and the two job lists.
     *
     * @return array{default: bool, aware: list<string>, not_aware: list<string>}
     */
    private function queueConfig(): array
    {
        $config = ['default' => true, 'aware' => [], 'not_aware' => []];
        $file = $this->config->base.'/config/multitenancy.php';

        if (! is_file($file)) {
            return $config;
        }

        $nodes = (new \PhpParser\NodeTraverser(new \PhpParser\NodeVisitor\NameResolver))->traverse((new \PhpParser\ParserFactory)->createForNewestSupportedVersion()->parse((string) file_get_contents($file)) ?? []);

        foreach ((new NodeFinder)->findInstanceOf($nodes, Node\ArrayItem::class) as $item) {
            $key = $item->key instanceof Node\Scalar\String_ ? $item->key->value : null;

            if ($key === 'queues_are_tenant_aware_by_default' && $item->value instanceof Node\Expr\ConstFetch) {
                $config['default'] = strtolower($item->value->name->toString()) !== 'false';
            }

            if (in_array($key, ['tenant_aware_jobs', 'not_tenant_aware_jobs'], true) && $item->value instanceof Node\Expr\Array_) {
                foreach ($item->value->items as $job) {
                    if ($job?->value instanceof Node\Expr\ClassConstFetch && $job->value->class instanceof Node\Name) {
                        $config[$key === 'tenant_aware_jobs' ? 'aware' : 'not_aware'][] = ltrim($job->value->class->toString(), '\\');
                    }
                }
            }
        }

        return $config;
    }
}
