<?php

declare(strict_types=1);

namespace Tey\Standards\Preflight;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/**
 * The turn-key starter's defaults (Jasper, Nov 27): queue timeouts, performance defaults and Telescope's setup. Each reads config and the
 * repository statically, fails closed (an unreadable worker command, gate or filter is a failure, not a pass) and names its fix. Advisory
 * level, like every new check group, so a minor bump never turns CI red; parts marked advisory below only warn.
 */
final readonly class StarterCheck implements Check
{
    public const NAMES = ['queue.config', 'perf.defaults', 'telescope.config'];

    /** The reference worker (starter#181). */
    private const WORKER = '`php artisan queue:work --timeout=85 --tries=3 --backoff=10,60,300 --max-jobs=500 --max-time=3600`, with retry_after 180';

    private const CLOUD_FLEX_TIMEOUT = 85;

    private const PAGE_QUERIES = 45;

    private const TELESCOPE_TABLES = ['telescope_entries', 'telescope_entries_tags', 'telescope_monitoring'];

    public function __construct(private string $check, private Repository $config, private string $base) {}

    public function name(): string
    {
        return $this->check;
    }

    public function level(): Level
    {
        return Level::Advisory;
    }

    public function needsSecrets(): bool
    {
        return false;
    }

    public function run(): Result
    {
        if (! $this->requires('tey/kit')) {
            return Result::pass('Not applicable: the app does not require tey/kit (the starter stack).');
        }

        [$fails, $warns, $ok] = match ($this->check) {
            'queue.config' => $this->queue(),
            'perf.defaults' => $this->perf(),
            'telescope.config' => $this->telescope(),
            default => throw new InvalidArgumentException('Unknown starter check: '.$this->check),
        };

        if ($fails !== []) {
            return Result::fail(implode(' ', [...$fails, ...$warns]));
        }

        return $warns !== [] ? Result::warn(implode(' ', $warns)) : Result::pass($ok);
    }

    /** @return array{0: list<string>, 1: list<string>, 2: string} */
    private function queue(): array
    {
        $fails = $warns = [];
        $name = (string) $this->config->get('queue.default');
        $connection = (array) $this->config->get('queue.connections.'.$name, []);
        $driver = (string) ($connection['driver'] ?? $name);
        $jobs = $this->jobs();

        if ($driver === 'sync') {
            $inline = array_keys(array_filter($jobs, fn (array $job): bool => ! $job['inline']));
            if ($this->production() && $inline !== []) {
                $warns[] = 'QUEUE_CONNECTION=sync in production, but '.count($inline).' ShouldQueue job(s) (such as '.$inline[0].') would run inside the request. Use a real queue, or mark a job meant to run inline with `@runs-inline` in its docblock.';
            }
        } else {
            $worker = $this->worker();
            if ($worker === null) {
                $fails[] = 'No `queue:work` command found (preflight.queue.worker, docs/deploy.md, cloud.yml, .forge/, Procfile or supervisor config), so its timeouts cannot be checked. Commit the worker command, for example '.self::WORKER.'.';
            } else {
                [$timeout, $tries] = $worker;
                $retry = $driver === 'cloud' ? $this->config->get('preflight.queue.visibility_timeout') : ($connection['retry_after'] ?? 90);
                if (is_numeric($retry) && $timeout >= (int) $retry) {
                    $fails[] = "The worker's --timeout={$timeout} is at or above ".($driver === 'cloud' ? 'the visibility timeout' : 'retry_after')." ({$retry}), so a running job is handed to a second worker. Keep --timeout below it: ".self::WORKER.'.';
                }
                if ($driver === 'cloud' && $this->config->get('preflight.queue.cloud_tier', 'flex') === 'flex' && $timeout > self::CLOUD_FLEX_TIMEOUT) {
                    $fails[] = "The worker's --timeout={$timeout} is above ".self::CLOUD_FLEX_TIMEOUT.' on a Laravel Cloud Flex queue. Use --timeout=85, and move jobs that need longer to a Pro queue.';
                }
                foreach ($jobs as $class => $job) {
                    if ($job['timeout'] !== null && $job['timeout'] > $timeout) {
                        $fails[] = "{$class}::\$timeout ({$job['timeout']}) is above the worker's --timeout={$timeout}; the worker kills it first. Lower it, or give it its own queue and worker.";
                    }
                    if ($job['tries'] !== null && $job['tries'] > $tries) {
                        $fails[] = "{$class}::\$tries ({$job['tries']}) is above the worker's --tries={$tries}. Lower it, or raise the worker's --tries.";
                    }
                }
            }
        }

        if (! $this->scheduled('queue:prune-failed', 'PruneFailedJobsCommand')) {
            $fails[] = 'queue:prune-failed is not scheduled. Add `Schedule::command(\'queue:prune-failed --hours=168\')->daily();` to routes/console.php.';
        }

        return [$fails, $warns, 'The queue worker, retry_after, job timeouts and failed-job pruning agree.'];
    }

    /** @return array{0: list<string>, 1: list<string>, 2: string} */
    private function perf(): array
    {
        $fails = $warns = [];
        $php = $this->read(['app/Providers/*.php', 'bootstrap/app.php']);

        if (! preg_match('/Model::(shouldBeStrict|preventLazyLoading)\s*\(/', $php)) {
            $fails[] = 'Lazy loading is not prevented outside production. Add `Model::preventLazyLoading(! $this->app->isProduction());` to AppServiceProvider::boot() (`Model::shouldBeStrict()` also counts, but is optional).';
        }

        if ($this->production()) {
            $store = (string) $this->config->get('cache.default');
            $databaseCache = $this->config->get('cache.stores.'.$store.'.driver') === 'database';
            if ($databaseCache) {
                $message = "Production's cache store ({$store}) is the database, so cache reads, writes and locks are queries. Use Redis or Valkey (Laravel Cloud's KV store) for CACHE_STORE.";
                if ($this->config->get('app.profile', getenv('APP_PROFILE') ?: null) === 'iterating') {
                    $warns[] = $message.' (Advisory on the iterating profile.)';
                } else {
                    $fails[] = $message;
                }
            }
        }

        if ($this->requires('laravel/nightwatch')) {
            if ($this->config->get('nightwatch.ignore_cache_events') !== true) {
                $fails[] = 'Nightwatch records cache events. Set NIGHTWATCH_IGNORE_CACHE_EVENTS=true.';
            }
            $filter = preg_match('/Nightwatch::rejectQueries\s*\(([^;]*)/', $php, $f) ? $f[1] : '';
            if (! str_contains($filter, 'cache_locks') || ! preg_match('/\bcache\b(?!_locks)/', $filter)) {
                $fails[] = 'Nightwatch\'s query filter does not drop the cache and cache_locks tables. Add `Nightwatch::rejectQueries(fn ($query) => preg_match(\'/\b(cache|cache_locks)\b/\', $query->sql));` to AppServiceProvider::boot().';
            }
        }

        $unindexed = $this->unindexedForeignKeys();
        if ($unindexed !== []) {
            $fails[] = 'Foreign key column(s) without an index: '.implode(', ', array_slice($unindexed, 0, 10)).(count($unindexed) > 10 ? ', …' : '').'. Add `->index()` (PostgreSQL does not index foreign keys itself).';
        }

        $report = (string) $this->config->get('preflight.perf.query_report', 'storage/app/kit-query-report.json');
        if (is_file($this->base.'/'.$report)) {
            $heavy = [];
            $this->heavyPages(json_decode((string) file_get_contents($this->base.'/'.$report), true), $heavy);
            if ($heavy !== []) {
                $warns[] = 'Pages over '.self::PAGE_QUERIES.' queries in kit:query-report: '.implode(', ', array_slice($heavy, 0, 10)).'. Eager-load or cache what repeats. (Advisory.)';
            }
        }

        return [$fails, $warns, 'Strict models, a non-database production cache, Nightwatch\'s cache filters and foreign key indexes are in place.'];
    }

    /** @return array{0: list<string>, 1: list<string>, 2: string} */
    private function telescope(): array
    {
        if (! $this->requires('laravel/telescope')) {
            return [['laravel/telescope is not installed. Run `composer require laravel/telescope` and `php artisan telescope:install` (the cookbook recipe "Telescope").'], [], ''];
        }

        $fails = [];
        $provider = $this->read(['app/Providers/TelescopeServiceProvider.php']);
        $gate = preg_match('/function\s+gate\s*\([^)]*\)[^{]*\{([\s\S]*?)\n    \}/', $provider, $m) ? $m[1] : '';
        $email = '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}|->email\b|\[\s*[\'"]email[\'"]\s*\]/';

        if ($gate === '') {
            $fails[] = 'No gate() found in app/Providers/TelescopeServiceProvider.php, so Telescope\'s access cannot be checked. Define viewTelescope there.';
        } else {
            if (preg_match('/=>\s*true\b|return\s+true\s*;/', $gate) || preg_match('/environment\(|isLocal\(|isProduction\(/', $gate) && ! preg_match('/Operators::allows\(|->can\(/', $gate)) {
                $fails[] = 'Telescope\'s gate is open (it returns true, or decides by environment alone).';
            }
            if (preg_match($email, $gate)) {
                $fails[] = 'Telescope\'s gate names email addresses.';
            }
            if (! preg_match('/Operators::allows\(|->can\(/', $gate)) {
                $fails[] = 'Telescope\'s gate does not ask the kit\'s Operators::allows() or a permission through a policy.';
            }
        }
        if (preg_match('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $this->read(['config/telescope.php']))) {
            $fails[] = 'config/telescope.php names email addresses.';
        }
        if ($fails !== []) {
            $fails[] = 'Gate by operator role or permission: `Gate::define(\'viewTelescope\', fn ($user): bool => Operators::allows($user));`, with no hard-coded emails.';
        }

        $markers = ['Telescope::filter(', 'log_everything', 'isReportableException', 'isFailedRequest', 'isFailedJob', 'isScheduledTask', 'isSlowQuery', 'hasMonitoredTag'];
        $missing = array_values(array_filter($markers, fn (string $marker): bool => ! str_contains($provider, $marker)));
        if ($missing !== []) {
            $fails[] = 'Telescope\'s production filter is not the cookbook rule 72 steady-state filter (missing '.implode(', ', $missing).'). Copy the filter from the recipe "Telescope".';
        }

        if (! preg_match('/telescope:prune[^;]*->daily\w*\(|PruneCommand[^;]*->daily\w*\(/', $this->schedule())) {
            $fails[] = 'telescope:prune is not scheduled daily. Add `Schedule::command(\'telescope:prune --hours=48\')->daily();` to routes/console.php.';
        }

        if ($this->config->get('kit.tenancy.rls.enabled')) {
            $except = (array) $this->config->get('kit.tenancy.rls.except', []);
            $absent = array_values(array_diff(self::TELESCOPE_TABLES, $except));
            if ($absent !== []) {
                $fails[] = 'Row-level security is on, but kit.tenancy.rls.except lacks '.implode(', ', $absent).', so Telescope cannot write its entries. Add them to the list.';
            }
        }

        return [$fails, [], 'Telescope is installed, gated by operator role or permission, filtered as rule 72 says and pruned daily.'];
    }

    /** @return ?array{0: int, 1: int} the worker's --timeout and --tries (Laravel's defaults when omitted) */
    private function worker(): ?array
    {
        $configured = $this->config->get('preflight.queue.worker');
        $text = is_string($configured) && $configured !== '' ? $configured : $this->read(['docs/deploy.md', 'cloud.yml', 'cloud.yaml', '.forge/*', 'Procfile', '*supervisor*.conf', 'deploy/*']);
        if (! preg_match('/queue:work[^\n`]*/', $text, $m)) {
            return null;
        }

        return [
            preg_match('/--timeout[= ](\d+)/', $m[0], $t) ? (int) $t[1] : 60,
            preg_match('/--tries[= ](\d+)/', $m[0], $r) ? (int) $r[1] : 1,
        ];
    }

    /** @return array<string, array{timeout: ?int, tries: ?int, inline: bool}> the app's ShouldQueue classes */
    private function jobs(): array
    {
        $jobs = [];
        foreach ($this->files('app') as $file) {
            $php = (string) file_get_contents($file);
            if (! preg_match('/class\s+(\w+)[^{]*implements[^{]*\bShouldQueue\b/', $php, $class)) {
                continue;
            }
            $jobs[$class[1]] = [
                'timeout' => preg_match('/\$timeout\s*=\s*(\d+)/', $php, $t) ? (int) $t[1] : null,
                'tries' => preg_match('/\$tries\s*=\s*(\d+)/', $php, $r) ? (int) $r[1] : null,
                'inline' => str_contains($php, '@runs-inline'),
            ];
        }

        return $jobs;
    }

    /** @return list<string> table.column for each foreign key a migration declares without an index */
    private function unindexedForeignKeys(): array
    {
        $found = [];
        foreach ($this->files('database/migrations') as $file) {
            $php = (string) file_get_contents($file);
            preg_match_all('/Schema::(?:create|table)\(\s*[\'"](\w+)[\'"]([\s\S]*?)\n\s*\}\);/', $php, $tables, PREG_SET_ORDER);
            foreach ($tables as [, $table, $body]) {
                $indexed = [];
                preg_match_all('/->(?:index|unique|primary)\(\s*\[?\s*[\'"](\w+)[\'"]/', $body, $idx);
                foreach ($idx[1] as $column) {
                    $indexed[$column] = true;
                }
                foreach (explode(';', $body) as $statement) {
                    $own = preg_match('/->(index|unique|primary)\(\s*\)/', $statement);
                    if (preg_match('/->foreignId\(\s*[\'"](\w+)[\'"]/', $statement, $c) && ! $own && ! isset($indexed[$c[1]])) {
                        $found[] = $table.'.'.$c[1];
                    }
                    if (preg_match('/->foreign\(\s*[\'"](\w+)[\'"]/', $statement, $c) && ! isset($indexed[$c[1]]) && ! preg_match('/->(?:foreignId|unsignedBigInteger|\w+)\(\s*[\'"]'.$c[1].'[\'"][^;]*->(index|unique|primary)\(\s*\)/', $body)) {
                        $found[] = $table.'.'.$c[1];
                    }
                }
            }
        }

        return array_values(array_unique($found));
    }

    /** @param list<string> $heavy */
    private function heavyPages(mixed $node, array &$heavy): void
    {
        if (! is_array($node)) {
            return;
        }
        if (is_int($node['queries'] ?? null) && $node['queries'] > self::PAGE_QUERIES) {
            $heavy[] = (string) ($node['uri'] ?? $node['route'] ?? $node['page'] ?? $node['url'] ?? '?').' ('.$node['queries'].')';
        }
        foreach ($node as $child) {
            $this->heavyPages($child, $heavy);
        }
    }

    private function scheduled(string ...$needles): bool
    {
        $schedule = $this->schedule();
        foreach ($needles as $needle) {
            if (str_contains($schedule, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function schedule(): string
    {
        return $this->read(['routes/console.php', 'app/Console/Kernel.php', 'bootstrap/app.php']);
    }

    private function requires(string $package): bool
    {
        $composer = json_decode(is_file($this->base.'/composer.json') ? (string) file_get_contents($this->base.'/composer.json') : '', true);

        return is_array($composer) && (isset($composer['require'][$package]) || isset($composer['require-dev'][$package]));
    }

    private function production(): bool
    {
        return $this->config->get('app.env') === 'production';
    }

    /** @param list<string> $patterns */
    private function read(array $patterns): string
    {
        $text = '';
        foreach ($patterns as $pattern) {
            foreach (glob($this->base.'/'.$pattern) ?: [] as $file) {
                $text .= is_file($file) ? (string) file_get_contents($file)."\n" : '';
            }
        }

        return $text;
    }

    /** @return list<string> */
    private function files(string $dir): array
    {
        if (! is_dir($this->base.'/'.$dir)) {
            return [];
        }
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base.'/'.$dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
