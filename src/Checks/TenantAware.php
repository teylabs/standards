<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use Tey\Standards\Ignored;
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
 * Which models are tenant-scoped: Configuration::$tenantModels, when the app sets it, is the whole answer (traits a model uses, or classes it is
 * or extends). Otherwise they are detected: Spatie's UsesTenantConnection; a tenant-style trait (BelongsToTenant and the like, or kit's BelongsToWorkspace; never kit's
 * HasWorkspaces, the user's memberships);
 * a global scope that names a tenant (a scope class or a closure on a tenant key); or, for a single-database app scoped by a column, a tenant key
 * (Configuration::$tenantKeys, default workspace_id and tenant_id) in $fillable or in the model's table in database/migrations. Active when the
 * app requires spatie/laravel-multitenancy, tey/kit or tey/concierge, or sets $tenantModels. An active rule that finds no tenant-scoped model at
 * all reports an advisory, never a silent pass: it checked nothing. Deliberate cross-tenant admin commands are declared in the allowlist (a
 * reason and an expiry), never by a marker. A landlord command, one that runs before any tenant exists (it creates the first owner or sends
 * an invite), is declared in Configuration::$landlordCommands with its reason. Nothing is skipped implicitly: an entry with an empty reason is
 * reported and the command is still checked, and an entry that names no command in the source roots, or a command that is also TenantAware,
 * is reported too.
 */
final class TenantAware
{
    private const COMMAND = 'Illuminate\Console\Command';

    /** Workspace traits that scope a model's queries: tey/kit's Tey\Kit\Features\Workspaces\Concerns\BelongsToWorkspace. */
    private const WORKSPACE_SCOPE_TRAITS = ['BelongsToWorkspace'];

    /** Membership traits on the user model, never a tenant scope: tey/kit's Tey\Kit\Features\Workspaces\Concerns\HasWorkspaces. */
    private const MEMBERSHIP_TRAITS = ['HasWorkspaces', 'HasWorkspace'];

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

        $landlord = $this->landlordCommands($classes);
        $tenantModels = $this->tenantModels($classes);

        if ($tenantModels === []) {
            return [...$landlord['violations'], ['target' => 'tests/Arch/configuration.php', 'check' => 'tenant-aware checked nothing: no tenant-scoped model found', 'advisory' => true,
                'hint' => 'This app has tenancy, but no model was recognised as tenant-scoped, so no command or job was checked. Set Configuration::$tenantModels to the app\'s tenant-scoped models (or their trait or base class), or $tenantKeys to the column it scopes by.']];
        }

        $queue = $this->queueConfig();
        $violations = $landlord['violations'];

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

            if ($kind === 'command' && ! in_array($name, $landlord['commands'], true) && ! $this->usesAny($class, [self::COMMAND_TRAIT], $classes) && ! $this->scopedByArgument($class, $tenantModels)) {
                $violations[] = ['target' => $path, 'check' => $name.' touches tenant-scoped '.$model.' without TenantAware', 'line' => $line,
                    'hint' => 'Use Spatie\Multitenancy\Commands\Concerns\TenantAware in the command (it then runs per tenant: --tenant=*), or resolve one tenant from an argument or option with findOrFail/firstOrFail (or find plus a failing null check) and do all of handle()\'s work inside $tenant->run(fn ...) or execute(fn ...). A deliberate cross-tenant admin command goes in the allowlist with a reason and an expiry; a landlord command that runs before any tenant exists goes in Configuration::$landlordCommands with its reason.'];
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

    /**
     * A command scoped to ONE tenant named by an argument or option: handle() resolves it with X::findOrFail(...), a query ending in
     * ->firstOrFail(), or X::find(...) / ->first() followed by `if (! $t) { ...; return / throw / abort }`, where the lookup reads
     * $this->argument() or $this->option() (directly or through a variable assigned from one). All the work then runs inside a closure
     * passed to $t->run(...) or $t->execute(...). Conservative: handle() may hold only the argument reads, the lookup, the null check,
     * plain output lines ($this->info/line/...) whose arguments call nothing, the run/execute call and a constant return. Any other
     * statement, a tenant-model reference anywhere outside that closure (including other methods), an unchecked find(), a nullsafe
     * ->run, or a tenant from anywhere but an argument or option, and the command still needs TenantAware.
     *
     * @param  array<string, true>  $tenantModels
     */
    private function scopedByArgument(Node\Stmt\Class_ $class, array $tenantModels): bool
    {
        $handle = $class->getMethod('handle');

        if ($handle === null || $handle->stmts === null) {
            return false;
        }

        $finder = new NodeFinder;
        $input = [];
        $nullable = [];
        $proven = [];
        $callbacks = [];
        $ran = false;
        $calls = fn (Node $node): bool => $finder->findFirst([$node], fn (Node $inner): bool => $inner instanceof Node\Expr\CallLike || $inner instanceof Node\Expr\Closure
            || $inner instanceof Node\Expr\ArrowFunction || $inner instanceof Node\Expr\Assign || $inner instanceof Node\Expr\AssignOp || $inner instanceof Node\Expr\AssignRef) !== null;
        $reads = fn (Node\Expr $expr): bool => $expr instanceof Node\Expr\MethodCall && $expr->var instanceof Node\Expr\Variable && $expr->var->name === 'this'
            && $expr->name instanceof Node\Identifier && in_array($expr->name->toString(), ['argument', 'option'], true) && ! $expr->isFirstClassCallable()
            && array_filter($expr->getArgs(), fn (Node\Arg $arg): bool => ! $arg->value instanceof Node\Scalar\String_) === [];
        $unwrap = fn (Node\Expr $expr): Node\Expr => $expr instanceof Node\Expr\Cast ? $expr->expr : $expr;
        $output = fn (Node\Expr $expr): bool => $expr instanceof Node\Expr\MethodCall && $expr->var instanceof Node\Expr\Variable && $expr->var->name === 'this'
            && $expr->name instanceof Node\Identifier && in_array($expr->name->toString(), ['info', 'line', 'error', 'warn', 'comment', 'question', 'alert', 'newLine'], true)
            && array_filter($expr->getArgs(), fn (Node\Arg $arg): bool => $calls($arg->value)) === [];
        // The lookup: a chain rooted in a static call on a class, reading an argument or option, ending in findOrFail/firstOrFail (proven) or find/first (nullable).
        $lookup = function (Node\Expr $expr) use ($finder, $reads, &$input): ?bool {
            if (! ($expr instanceof Node\Expr\StaticCall || $expr instanceof Node\Expr\MethodCall) || ! $expr->name instanceof Node\Identifier) {
                return null;
            }
            $end = $expr->name->toString();
            $root = $expr;
            while ($root instanceof Node\Expr\MethodCall) {
                $root = $root->var;
            }
            if (! $root instanceof Node\Expr\StaticCall || ! $root->class instanceof Node\Name || in_array($root->class->toLowerString(), ['self', 'static', 'parent'], true)) {
                return null;
            }
            $sourced = $finder->findFirst([$expr], fn (Node $node): bool => ($node instanceof Node\Expr\MethodCall && $reads($node))
                || ($node instanceof Node\Expr\Variable && is_string($node->name) && isset($input[$node->name]))) !== null;
            $foreign = $finder->findFirst([$expr], fn (Node $node): bool => ($node instanceof Node\Expr\Variable && $node->name !== 'this' && ! (is_string($node->name) && isset($input[$node->name])))
                || $node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction || $node instanceof Node\Expr\FuncCall || $node instanceof Node\Expr\New_
                || ($node instanceof Node\Expr\MethodCall && $node->var instanceof Node\Expr\Variable && $node->var->name === 'this' && ! $reads($node))) !== null;
            if (! $sourced || $foreign) {
                return null;
            }

            return match ($end) {
                'findOrFail', 'firstOrFail', 'sole' => true,
                'find', 'first' => false,
                default => null,
            };
        };
        $variable = fn (?Node $node): ?string => $node instanceof Node\Expr\Variable && is_string($node->name) ? $node->name : null;
        // The null check: if (! $t) / ($t === null) / (null === $t) / (is_null($t)) whose body only prints and ends by failing.
        $guard = function (Node\Stmt\If_ $if) use ($variable, $output): ?string {
            $cond = $if->cond;
            $name = match (true) {
                $cond instanceof Node\Expr\BooleanNot => $variable($cond->expr),
                $cond instanceof Node\Expr\BinaryOp\Identical && $cond->right instanceof Node\Expr\ConstFetch && $cond->right->name->toLowerString() === 'null' => $variable($cond->left),
                $cond instanceof Node\Expr\BinaryOp\Identical && $cond->left instanceof Node\Expr\ConstFetch && $cond->left->name->toLowerString() === 'null' => $variable($cond->right),
                $cond instanceof Node\Expr\FuncCall && $cond->name instanceof Node\Name && $cond->name->toLowerString() === 'is_null' && count($cond->getArgs()) === 1 => $variable($cond->getArgs()[0]->value),
                default => null,
            };
            if ($name === null || $if->elseifs !== [] || $if->else !== null || $if->stmts === []) {
                return null;
            }
            $last = $if->stmts[count($if->stmts) - 1];
            $fails = $last instanceof Node\Stmt\Return_ || ($last instanceof Node\Stmt\Expression && $last->expr instanceof Node\Expr\Throw_)
                || ($last instanceof Node\Stmt\Expression && $last->expr instanceof Node\Expr\FuncCall && $last->expr->name instanceof Node\Name && in_array($last->expr->name->toLowerString(), ['abort', 'abort_if', 'abort_unless'], true))
                || ($last instanceof Node\Stmt\Expression && $last->expr instanceof Node\Expr\MethodCall && $last->expr->var instanceof Node\Expr\Variable && $last->expr->var->name === 'this' && $last->expr->name instanceof Node\Identifier && $last->expr->name->toString() === 'fail');
            foreach (array_slice($if->stmts, 0, -1) as $stmt) {
                if (! $stmt instanceof Node\Stmt\Expression || ! $output($stmt->expr)) {
                    return null;
                }
            }

            return $fails ? $name : null;
        };
        // The run: $t->run(closure) or $t->execute(closure) on a proven tenant (also chained straight onto a findOrFail lookup).
        $run = function (Node\Expr $expr) use (&$proven, &$callbacks, $variable, $lookup): bool {
            if (! $expr instanceof Node\Expr\MethodCall || ! $expr->name instanceof Node\Identifier || ! in_array($expr->name->toString(), ['run', 'execute'], true)
                || $expr->isFirstClassCallable() || count($expr->getArgs()) !== 1) {
                return false;
            }
            $callback = $expr->getArgs()[0]->value;
            $receiver = $variable($expr->var);
            if (! ($callback instanceof Node\Expr\Closure || $callback instanceof Node\Expr\ArrowFunction)
                || ! (($receiver !== null && isset($proven[$receiver])) || ($receiver === null && $lookup($expr->var) === true))) {
                return false;
            }
            $callbacks[] = $callback;

            return true;
        };

        foreach ($handle->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Nop) {
                continue;
            }
            if ($stmt instanceof Node\Stmt\Expression && $stmt->expr instanceof Node\Expr\Assign && ($name = $variable($stmt->expr->var)) !== null && $name !== 'this'
                && ! isset($input[$name]) && ! isset($nullable[$name]) && ! isset($proven[$name])) {
                $value = $unwrap($stmt->expr->expr);
                if (! $ran && $value instanceof Node\Expr\MethodCall && $reads($value)) {
                    $input[$name] = true;

                    continue;
                }
                if (! $ran && ($found = $lookup($stmt->expr->expr)) !== null) {
                    $found ? $proven[$name] = true : $nullable[$name] = true;

                    continue;
                }
                if ($run($stmt->expr->expr)) {
                    $ran = true;

                    continue;
                }

                return false;
            }
            if ($stmt instanceof Node\Stmt\If_ && ! $ran && ($name = $guard($stmt)) !== null && isset($nullable[$name])) {
                unset($nullable[$name]);
                $proven[$name] = true;

                continue;
            }
            if ($stmt instanceof Node\Stmt\Expression && ($output($stmt->expr) || $run($stmt->expr))) {
                $ran = $ran || ! $output($stmt->expr);

                continue;
            }
            if ($stmt instanceof Node\Stmt\Return_ && $stmt->expr !== null && $run($stmt->expr)) {
                $ran = true;

                continue;
            }
            if ($stmt instanceof Node\Stmt\Return_ && ($stmt->expr === null || $stmt->expr instanceof Node\Scalar\Int_ || $stmt->expr instanceof Node\Expr\ClassConstFetch)) {
                continue;
            }

            return false;
        }

        if (! $ran || $nullable !== []) {
            return false;
        }

        // Every tenant-model reference in the class lies inside a run/execute callback.
        foreach ($finder->findInstanceOf($class->stmts, Node\Name::class) as $name) {
            $resolved = ltrim(($name->getAttribute('resolvedName') ?? $name)->toString(), '\\');
            if (isset($tenantModels[$resolved]) && array_filter($callbacks, fn (Node $callback): bool => $name->getStartFilePos() >= $callback->getStartFilePos() && $name->getEndFilePos() <= $callback->getEndFilePos()) === []) {
                return false;
            }
        }

        return true;
    }

    /**
     * The declared landlord commands that count, and a finding for each declaration that does not: an empty reason, a class that is not a
     * command in the source roots, or a command that is also TenantAware (it cannot be both).
     *
     * @param  array<string, array{0: string, 1: Node\Stmt\Class_}>  $classes
     * @return array{commands: list<string>, violations: list<array{target: string, check: string, line?: int, hint: string}>}
     */
    private function landlordCommands(array $classes): array
    {
        $commands = [];
        $violations = [];

        foreach ($this->config->landlordCommands as $name => $reason) {
            $name = ltrim((string) $name, '\\');
            [$path, $class] = $classes[$name] ?? [null, null];

            if ($class === null || $this->kind($class, $classes) !== 'command') {
                $violations[] = ['target' => 'tests/Arch/configuration.php', 'check' => 'landlord command '.$name.' is not a command in the source roots',
                    'hint' => 'Configuration::$landlordCommands names a class that is not an artisan command under the source roots. Fix the class name, or remove the entry.'];
            } elseif (! is_string($reason) || trim($reason) === '') {
                $violations[] = ['target' => $path, 'check' => $name.' is declared a landlord command without a reason', 'line' => $class->getStartLine(),
                    'hint' => 'Give the entry in Configuration::$landlordCommands the reason it runs outside any tenant, such as \'creates the first owner before any workspace exists\'. Until it has one, the command is checked as tenant work.'];
            } elseif ($this->usesAny($class, [self::COMMAND_TRAIT], $classes)) {
                $violations[] = ['target' => $path, 'check' => $name.' is declared a landlord command but is TenantAware', 'line' => $class->getStartLine(),
                    'hint' => 'A TenantAware command runs once per tenant, so it is not a landlord command. Remove the entry from Configuration::$landlordCommands, or the TenantAware trait.'];
            } else {
                $commands[] = $name;
            }
        }

        return ['commands' => $commands, 'violations' => $violations];
    }

    /** spatie/laravel-multitenancy, tey/kit or tey/concierge (workspaces) is required, or the app named its own tenant-model traits or classes. */
    private function active(): bool
    {
        $file = $this->config->base.'/composer.json';
        $composer = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $packages = is_array($composer) ? [...array_keys($composer['require'] ?? []), ...array_keys($composer['require-dev'] ?? [])] : [];

        return array_intersect(['spatie/laravel-multitenancy', 'tey/kit', 'tey/concierge'], $packages) !== [] || $this->configured();
    }

    /**
     * @param  array<string, array{0: string, 1: Node\Stmt\Class_}>  $classes
     * @return array<string, true>
     */
    private function tenantModels(array $classes): array
    {
        if ($this->configured()) {
            return $this->configuredModels($classes);
        }

        $models = $this->configuredModels($classes);
        $tables = $this->migratedTables();

        foreach ($classes as $name => [, $class]) {
            if (isset($models[$name]) || ! $this->isModel($class, $classes)) {
                continue;
            }

            if ($this->tenantTrait($class, $classes) || $this->tenantScope($class) || $this->fillsTenantKey($class) || isset($tables[$this->table($class)])) {
                $models[$name] = true;
            }
        }

        return $models;
    }

    /** Whether the app set $tenantModels itself (then it is the whole answer). */
    private function configured(): bool
    {
        return array_values($this->config->tenantModels) !== self::DEFAULT_MODELS;
    }

    /**
     * @param  array<string, array{0: string, 1: Node\Stmt\Class_}>  $classes
     * @return array<string, true>
     */
    private function configuredModels(array $classes): array
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
    private function isModel(Node\Stmt\Class_ $class, array $classes): bool
    {
        $ancestors = $this->ancestors($class, $classes);

        return in_array('Illuminate\\Database\\Eloquent\\Model', $ancestors, true) || in_array('Illuminate\\Foundation\\Auth\\User', $ancestors, true)
            || in_array('Illuminate\\Database\\Eloquent\\Relations\\Pivot', $ancestors, true);
    }

    /**
     * A tenant-scoping trait. For workspaces that is only tey/kit's scoping trait,
     * Tey\Kit\Features\Workspaces\Concerns\BelongsToWorkspace (it adds the `workspace` global scope), matched by short name
     * so an app's own BelongsToWorkspace counts too. Kit's other workspace traits do not scope a model's queries and never count:
     * HasWorkspaces (Tey\Kit\Features\Workspaces\Concerns\HasWorkspaces) is the user model's memberships and team roles,
     * IsKitWorkspace is the workspace model itself, and GuardsStaleWrites is optimistic locking. A model using HasWorkspaces
     * and BelongsToWorkspace is still scoped, through BelongsToWorkspace. Tenant-named traits are matched as before:
     * BelongsToTenant, HasTenant, ScopedToTenant, TenantScoped, TenantOwned, TenantAware and the like.
     *
     * @param  array<string, array{0: string, 1: Node\Stmt\Class_}>  $classes
     */
    private function tenantTrait(Node\Stmt\Class_ $class, array $classes): bool
    {
        foreach ([$class, ...array_map(fn (string $parent): ?Node\Stmt\Class_ => $classes[$parent][1] ?? null, $this->ancestors($class, $classes))] as $node) {
            foreach ($node?->getTraitUses() ?? [] as $use) {
                foreach ($use->traits as $trait) {
                    $short = $trait->getLast();
                    if (in_array($short, self::MEMBERSHIP_TRAITS, true)) {
                        continue;
                    }
                    if (in_array($short, self::WORKSPACE_SCOPE_TRAITS, true) || preg_match('/^(BelongsTo|Has|ScopedTo|ScopedBy|For)Tenants?$|^Tenant(Scoped|Owned|Aware)$/', $short) === 1) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /** A global scope that names a tenant: addGlobalScope() with a scope class or closure naming a tenant or a tenant key, or #[ScopedBy] a tenant scope. */
    private function tenantScope(Node\Stmt\Class_ $class): bool
    {
        $finder = new NodeFinder;
        $tenantish = fn (Node $node): bool => $finder->findFirst([$node], fn (Node $inner): bool => ($inner instanceof Node\Name && preg_match('/(Tenant|Workspace)/', $inner->getLast()) === 1)
            || ($inner instanceof Node\Scalar\String_ && (in_array($inner->value, $this->config->tenantKeys, true) || preg_match('/(tenant|workspace)/i', $inner->value) === 1))) !== null;

        foreach ($finder->find($class->stmts, fn (Node $node): bool => ($node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\MethodCall) && $node->name instanceof Node\Identifier && $node->name->toString() === 'addGlobalScope') as $call) {
            if ($tenantish($call)) {
                return true;
            }
        }

        foreach ($class->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if ($attribute->name->getLast() === 'ScopedBy' && $tenantish($attribute)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function fillsTenantKey(Node\Stmt\Class_ $class): bool
    {
        foreach ($class->getProperties() as $property) {
            foreach ($property->props as $prop) {
                if ($prop->name->toString() === 'fillable' && $prop->default instanceof Node\Expr\Array_) {
                    foreach ($prop->default->items as $item) {
                        if ($item?->value instanceof Node\Scalar\String_ && in_array($item->value->value, $this->config->tenantKeys, true)) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    /** The model's table: its `$table`, else Laravel's convention (snake plural of the class name). */
    private function table(Node\Stmt\Class_ $class): string
    {
        foreach ($class->getProperties() as $property) {
            foreach ($property->props as $prop) {
                if ($prop->name->toString() === 'table' && $prop->default instanceof Node\Scalar\String_) {
                    return $prop->default->value;
                }
            }
        }

        return \Illuminate\Support\Str::snake(\Illuminate\Support\Str::pluralStudly((string) $class->name));
    }

    /**
     * Tables a migration under database/migrations gives a tenant key column: Schema::create or Schema::table with a tenant key (or a foreignIdFor a
     * Tenant or Workspace model).
     *
     * @return array<string, true>
     */
    private function migratedTables(): array
    {
        $tables = [];
        $directory = $this->config->base.'/database/migrations';

        if (! is_dir($directory)) {
            return [];
        }

        $finder = new NodeFinder;
        $parser = (new \PhpParser\ParserFactory)->createForNewestSupportedVersion();

        $migrations = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (str_ends_with($file->getFilename(), '.php')) {
                $migrations[str_replace('\\', '/', substr($file->getPathname(), strlen($this->config->base) + 1))] = $file;
            }
        }

        foreach (Ignored::filterKeys($this->config->base, $migrations) as $file) {

            try {
                $nodes = $parser->parse((string) file_get_contents($file->getPathname())) ?? [];
            } catch (\PhpParser\Error) {
                continue;
            }

            foreach ($finder->find($nodes, fn (Node $node): bool => $node instanceof Node\Expr\StaticCall && $node->name instanceof Node\Identifier && in_array($node->name->toString(), ['create', 'table'], true)) as $call) {
                $arguments = $call->getArgs();
                $table = $arguments[0]->value ?? null;

                if (! $table instanceof Node\Scalar\String_ || ! isset($arguments[1])) {
                    continue;
                }

                $keyed = $finder->findFirst([$arguments[1]->value], fn (Node $node): bool => $node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier
                    && ((($node->getArgs()[0]->value ?? null) instanceof Node\Scalar\String_ && in_array($node->getArgs()[0]->value->value, $this->config->tenantKeys, true))
                        || ($node->name->toString() === 'foreignIdFor' && ($node->getArgs()[0]->value ?? null) instanceof Node\Expr\ClassConstFetch && preg_match('/(Tenant|Workspace)$/', $node->getArgs()[0]->value->class instanceof Node\Name ? $node->getArgs()[0]->value->class->getLast() : '') === 1)));

                if ($keyed !== null) {
                    $tables[$table->value] = true;
                }
            }
        }

        return $tables;
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
