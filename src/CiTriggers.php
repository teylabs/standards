<?php

declare(strict_types=1);

namespace Tey\Standards;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final readonly class CiTriggers
{
    /** @param list<string> $defaultBranches */
    public function __construct(
        private string $base,
        private array $defaultBranches = ['main'],
        private ?string $distribution = null,
        private mixed $drafts = 'lint',
        private ?string $eventPath = null,
    ) {}

    public function distribution(): string
    {
        if (in_array($this->distribution, ['app', 'package'], true)) {
            return $this->distribution;
        }

        $composer = $this->base.'/composer.json';

        if (is_file($composer)) {
            $type = json_decode((string) file_get_contents($composer), true)['type'] ?? 'library';

            return $type === 'project' ? 'app' : 'package';
        }

        return is_file($this->base.'/artisan') ? 'app' : 'unknown';
    }

    /** @return list<string> */
    public function workflows(): array
    {
        $files = [...(glob($this->base.'/.github/workflows/*.yml') ?: []), ...(glob($this->base.'/.github/workflows/*.yaml') ?: [])];
        sort($files);

        return $files;
    }

    /** @return list<array{workflow: string, job: string, reason: string}> */
    public function findings(): array
    {
        return $this->report()['findings'];
    }

    /** @return list<array{workflow: string, job: string, reason: string}> */
    public function advisories(): array
    {
        return $this->report()['advisories'];
    }

    /** @return array{findings: list<array{workflow: string, job: string, reason: string}>, advisories: list<array{workflow: string, job: string, reason: string}>} */
    private function report(): array
    {
        $findings = [];
        $advisories = [];
        $readyRunners = [];
        $hasCiWorkflow = false;
        $distribution = $this->distribution();
        $fullDrafts = false;

        if (! in_array($this->drafts, ['lint', 'full'], true)) {
            $findings[] = ['workflow' => '.github/workflows', 'job' => 'workflow', 'reason' => 'drafts-invalid: drafts must be lint or full; got '.json_encode($this->drafts).'.'];
        } elseif ($this->drafts === 'full') {
            $private = $this->repositoryPrivate();
            $fullDrafts = $private !== true;

            if ($private === true) {
                $findings[] = ['workflow' => '.github/workflows', 'job' => 'workflow', 'reason' => 'drafts-full-private: drafts: full is only for public repos; private repos skip full suites on drafts.'];
            } elseif ($private === null) {
                $advisories[] = ['workflow' => '.github/workflows', 'job' => 'workflow', 'reason' => 'drafts-full-unverified: repository visibility could not be confirmed from GITHUB_EVENT_PATH; drafts: full is honoured locally.'];
            }
        }

        foreach ($this->workflows() as $path) {
            $file = str_replace('\\', '/', substr($path, strlen(rtrim($this->base, '/\\')) + 1));

            try {
                $workflow = Yaml::parseFile($path);
            } catch (ParseException) {
                $findings[] = ['workflow' => $file, 'job' => 'workflow', 'reason' => 'yaml: repair unreadable or invalid workflow YAML.'];

                continue;
            }

            if (! is_array($workflow)) {
                $findings[] = ['workflow' => $file, 'job' => 'workflow', 'reason' => 'yaml: use a workflow mapping.'];

                continue;
            }

            $events = $this->events($workflow);
            $hasCiWorkflow = $hasCiWorkflow || array_key_exists('push', $events) || array_key_exists('pull_request', $events);

            foreach ($this->inspect($workflow, $fullDrafts) as $finding) {
                $findings[] = ['workflow' => $file, ...$finding];
            }

            foreach ($workflow['jobs'] ?? [] as $name => $job) {
                if (! is_array($job)) {
                    continue;
                }

                if ($this->needsCache($job)) {
                    $advisories[] = ['workflow' => $file, 'job' => (string) $name, 'reason' => 'cache: Suggest measuring installs first; cache dependencies only above ~45 s cold or just over a minute boundary. Consolidate small jobs first.'];
                }

                if (array_key_exists('pull_request', $this->events($workflow)) && $this->readyCondition($job['if'] ?? true) === true) {
                    $readyRunners = [...$readyRunners, ...$this->readyRunners($job)];
                }
            }
        }

        if ($distribution === 'unknown') {
            $advisories[] = ['workflow' => '.github/workflows', 'job' => 'workflow', 'reason' => 'distribution: Unknown; set preflight.ci.distribution (PREFLIGHT_CI_DISTRIBUTION) to app or package. Matrix policy was not checked.'];
        }

        if ($distribution === 'package' && $hasCiWorkflow) {
            if (preg_match('/windows/i', implode(' ', $readyRunners)) !== 1) {
                $findings[] = ['workflow' => '.github/workflows', 'job' => 'workflow', 'reason' => 'windows-gap: test Windows on ready pull_request before merge; others run distributed packages there. Drafts may skip it.'];
            }

            if (preg_match('/macos/i', implode(' ', $readyRunners)) !== 1) {
                $advisories[] = ['workflow' => '.github/workflows', 'job' => 'workflow', 'reason' => 'macos: Suggest testing macOS on ready pull requests where relevant; no macOS leg was found.'];
            }
        }

        return compact('findings', 'advisories');
    }

    private function repositoryPrivate(): ?bool
    {
        $path = $this->eventPath ?? (getenv('GITHUB_EVENT_PATH') ?: '');

        if ($path === '' || ! stream_is_local($path) || ! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $private = json_decode((string) file_get_contents($path), true)['repository']['private'] ?? null;

        return is_bool($private) ? $private : null;
    }

    /** @param array<mixed> $workflow
     * @return array<string, mixed>
     */
    private function events(array $workflow): array
    {
        $events = $workflow['on'] ?? $workflow[true] ?? [];
        $events = is_string($events) ? [$events => null] : (is_array($events) ? $events : []);

        return array_is_list($events) ? array_fill_keys($events, null) : $events;
    }

    /**
     * @param  array<mixed>  $workflow
     * @return list<array{job: string, reason: string}>
     */
    private function inspect(array $workflow, bool $fullDrafts): array
    {
        $events = $this->events($workflow);
        $ciWorkflow = array_key_exists('push', $events) || array_key_exists('pull_request', $events);
        $jobs = $workflow['jobs'] ?? [];
        $findings = [];
        $hasDraftGuard = false;

        if (array_key_exists('push', $events)) {
            $push = $events['push'];
            $branches = is_array($push) ? ($push['branches'] ?? null) : null;
            $tagsOnly = is_array($push) && (isset($push['tags']) || isset($push['tags-ignore'])) && $branches === null && ! isset($push['branches-ignore']);

            if (! $tagsOnly && (! is_array($branches) || $branches === [] || array_diff($branches, $this->defaultBranches) !== [])) {
                $findings[] = ['job' => 'workflow', 'reason' => 'push-branches: limit branch pushes to ['.implode(', ', $this->defaultBranches).']; tag pushes are allowed.'];
            }
        }

        $pullRequest = array_key_exists('pull_request', $events);
        $types = is_array($events['pull_request'] ?? null) ? ($events['pull_request']['types'] ?? []) : [];

        foreach ($jobs as $name => $job) {
            if (! is_array($job)) {
                continue;
            }

            $name = (string) $name;
            $guard = $this->draftGuard($job['if'] ?? '');
            $hasDraftGuard = $hasDraftGuard || $guard;

            if (! $fullDrafts && $pullRequest && $guard && ! in_array('ready_for_review', $types, true)) {
                $findings[] = ['job' => $name, 'reason' => 'pull-request-types: include ready_for_review so draft-skipping jobs restart.'];
            }

            $concurrency = $job['concurrency'] ?? $workflow['concurrency'] ?? null;

            if ($ciWorkflow && ($concurrency === null || $concurrency === [] || $concurrency === '')) {
                $findings[] = ['job' => $name, 'reason' => 'concurrency: set workflow concurrency or concurrency on every job.'];
            }

            $matrix = $job['strategy']['matrix'] ?? [];

            if ($ciWorkflow && $this->distribution() === 'app' && $this->nonLinux($job['runs-on'] ?? '', $matrix)) {
                $findings[] = ['job' => $name, 'reason' => 'os-matrix: remove non-Linux legs on every event; our apps deploy on Linux only.'];
            }

            if ($pullRequest && ! $this->excludesPullRequest($job['if'] ?? '')) {
                if (! $fullDrafts && $this->tests($job) && ! $guard) {
                    $findings[] = ['job' => $name, 'reason' => 'draft-guard: skip test jobs while github.event.pull_request.draft is true.'];
                }

                if ($this->distribution() === 'app' && $this->multiplePhpVersions($matrix)) {
                    $findings[] = ['job' => $name, 'reason' => 'php-matrix: run one deploy PHP version on pull_request; select extra versions by github.event_name.'];
                }
            }

            if (! isset($job['timeout-minutes'])) {
                $findings[] = ['job' => $name, 'reason' => 'timeout: set timeout-minutes on every job.'];
            }
        }

        if (! $ciWorkflow) {
            return $findings;
        }

        foreach (['workflow' => $workflow['concurrency'] ?? null, ...array_map(fn (mixed $job): mixed => is_array($job) ? ($job['concurrency'] ?? null) : null, $jobs)] as $name => $concurrency) {
            $cancel = is_array($concurrency) ? ($concurrency['cancel-in-progress'] ?? false) : false;

            if ($cancel === true || (is_string($cancel) && preg_match('/^(?:true|\$\{\{\s*true\s*\}\})$/i', trim($cancel)) === 1)) {
                $findings[] = ['job' => (string) $name, 'reason' => 'concurrency: condition cancel-in-progress on pull_request so push/main runs finish.'];
            }

            $guard = $name === 'workflow' ? $hasDraftGuard : $this->draftGuard($jobs[$name]['if'] ?? '');

            if (! $fullDrafts && $guard && $concurrency !== null) {
                $group = is_array($concurrency) ? ($concurrency['group'] ?? '') : $concurrency;
                preg_match_all('/\$\{\{(.*?)\}\}/s', is_string($group) ? $group : '', $expressions);

                if (! array_any($expressions[1], fn (string $expression): bool => preg_match('/\bgithub\s*\.\s*event\s*\.\s*pull_request\s*\.\s*draft\b/', $expression) === 1)) {
                    $findings[] = ['job' => (string) $name, 'reason' => 'concurrency-draft: include github.event.pull_request.draft in the group expression so draft runs cannot cancel ready runs.'];
                }
            }
        }

        return $findings;
    }

    private function draftGuard(mixed $condition): bool
    {
        return is_string($condition) && preg_match('/(?:github\.event\.pull_request\.draft\s*(?:==\s*false|!=\s*true)|!\s*github\.event\.pull_request\.draft\b|false\s*==\s*github\.event\.pull_request\.draft)/', $condition) === 1;
    }

    private function excludesPullRequest(mixed $condition): bool
    {
        if (! is_string($condition)) {
            return false;
        }

        $condition = trim(str_replace(['${{', '}}'], '', $condition));

        return preg_match('/^github\.event_name\s*!=\s*[\'"]pull_request[\'"]$/', $condition) === 1
            || preg_match('/^github\.event_name\s*==\s*[\'"](?:push|schedule|workflow_dispatch)[\'"](?:\s*\|\|\s*github\.event_name\s*==\s*[\'"](?:push|schedule|workflow_dispatch)[\'"])*$/', $condition) === 1;
    }

    /** @param array<mixed> $job */
    private function tests(array $job): bool
    {
        foreach ($job['steps'] ?? [] as $step) {
            $run = $step['run'] ?? '';

            if (is_string($run) && preg_match('/(?:\bphp\s+artisan\s+test\b|(?:^|[\s\/;&|])(?:pest|phpunit|vitest)(?:\s|$)|\b(?:npm|composer)\s+test\b|\bnpx\s+playwright\b)/m', $run) === 1) {
                return true;
            }
        }

        return false;
    }

    private function multiplePhpVersions(mixed $matrix): bool
    {
        if (is_string($matrix)) {
            return ! str_contains($matrix, 'github.event_name') && preg_match('/[\'"]php(?:[-_]version)?[\'"]\s*:\s*\[([^\]]+)\]/', $matrix, $match) === 1 && str_contains($match[1], ',');
        }

        if (! is_array($matrix)) {
            return false;
        }

        foreach (['php', 'php-version', 'php_version'] as $key) {
            $versions = $matrix[$key] ?? [];

            if (is_array($versions)) {
                $versions = [...$versions, ...array_column($matrix['include'] ?? [], $key)];
            }

            if (is_array($versions) && count(array_unique($versions)) > 1) {
                return true;
            }

            if (is_string($versions) && ! str_contains($versions, 'github.event_name') && preg_match('/fromJSON\(\s*[\'"]\[([^\]]+)\]/i', $versions, $match) === 1 && str_contains($match[1], ',')) {
                return true;
            }
        }

        return false;
    }

    private function nonLinux(mixed $runner, mixed $matrix): bool
    {
        if (is_array($matrix)) {
            foreach (['os', 'operating-system', 'operating_system'] as $key) {
                $values = $matrix[$key] ?? [];
                $values = is_array($values) ? $values : [$values];

                if (array_any([...$values, ...array_column($matrix['include'] ?? [], $key)], fn ($value) => $this->nonLinux($value, []))) {
                    return true;
                }
            }
        }

        if (is_string($runner) && preg_match('/matrix\.([\w-]+)/', $runner, $match) === 1) {
            $runner = is_array($matrix) ? ($matrix[$match[1]] ?? []) : $matrix;

            if (is_array($runner) && is_array($matrix)) {
                $runner = [...$runner, ...array_column($matrix['include'] ?? [], $match[1])];
            }
        }

        if (is_string($runner) && str_contains($runner, '${{')) {
            return preg_match('/(?:windows|macos)/i', $runner) === 1;
        }

        $labels = is_array($runner) ? $runner : [$runner];

        return preg_match('/(?:windows|macos)/i', implode(' ', $labels)) === 1
            || ($labels !== [] && preg_match('/(?:ubuntu|linux)/i', implode(' ', $labels)) !== 1);
    }

    private function readyCondition(mixed $condition): ?bool
    {
        if (is_bool($condition)) {
            return $condition;
        }

        if (! is_string($condition)) {
            return null;
        }

        $condition = trim(str_replace(['${{', '}}'], '', $condition));
        $condition = preg_replace_callback('/github\.event_name\s*(==|!=)\s*[\'"]([^\'"]+)[\'"]/', fn ($m) => (($m[2] === 'pull_request') === ($m[1] === '==')) ? 'true' : 'false', $condition);
        $condition = str_replace('github.event.pull_request.draft', 'false', $condition);
        $condition = preg_replace_callback('/\b(true|false)\s*(==|!=)\s*(true|false)\b/', fn ($m) => (($m[1] === $m[3]) === ($m[2] === '==')) ? 'true' : 'false', $condition);

        while (preg_match('/\(([^()]*)\)/', $condition)) {
            $condition = preg_replace_callback('/\(([^()]*)\)/', fn ($m) => match ($this->readyCondition($m[1])) {
                true => 'true',
                false => 'false',
                null => 'unknown',
            }, $condition);
        }

        $condition = preg_replace_callback('/!\s*(true|false)\b/', fn ($m) => $m[1] === 'true' ? 'false' : 'true', $condition);

        while (preg_match('/\b(true|false)\s*&&\s*(true|false)\b/', $condition)) {
            $condition = preg_replace_callback('/\b(true|false)\s*&&\s*(true|false)\b/', fn ($m) => $m[1] === 'true' && $m[2] === 'true' ? 'true' : 'false', $condition);
        }

        while (preg_match('/\b(true|false)\s*\|\|\s*(true|false)\b/', $condition)) {
            $condition = preg_replace_callback('/\b(true|false)\s*\|\|\s*(true|false)\b/', fn ($m) => $m[1] === 'true' || $m[2] === 'true' ? 'true' : 'false', $condition);
        }

        return match (trim($condition)) {
            'true' => true,
            'false' => false,
            default => null,
        };
    }

    private function readyValue(mixed $value): mixed
    {
        if (! is_string($value) || ! str_contains($value, '${{')) {
            return $value;
        }

        $expression = trim(str_replace(['${{', '}}'], '', $value));

        if (preg_match('/^(.+?)\s*&&\s*(fromJSON\(.*?\)|\'[^\']*\')\s*\|\|\s*(.+)$/i', $expression, $match) === 1) {
            $ready = $this->readyCondition($match[1]);

            return $ready === null ? null : $this->readyValue('${{ '.($ready ? $match[2] : $match[3]).' }}');
        }

        if (preg_match('/^fromJSON\(\s*\'(.*)\'\s*\)$/is', $expression, $match) === 1) {
            return json_decode(str_replace("''", "'", $match[1]), true);
        }

        return preg_match('/^\'([^\']*)\'$/', $expression, $match) === 1 ? $match[1] : null;
    }

    /** @param array<mixed> $job
     * @return list<string>
     */
    private function readyRunners(array $job): array
    {
        $runner = $job['runs-on'] ?? '';

        if (! is_string($runner) || preg_match('/matrix\.([\w-]+)/', $runner, $match) !== 1) {
            $runner = $this->readyValue($runner);

            return is_array($runner) ? array_values(array_filter($runner, is_string(...))) : (is_string($runner) ? [$runner] : []);
        }

        $matrix = $this->readyValue($job['strategy']['matrix'] ?? []);

        if (! is_array($matrix)) {
            return [];
        }

        $rows = [[]];
        $keys = array_diff(array_keys($matrix), ['include', 'exclude']);

        foreach ($keys as $key) {
            $values = $this->readyValue($matrix[$key]);

            if (! is_array($values)) {
                return [];
            }

            $next = [];

            foreach ($rows as $row) {
                foreach ($values as $value) {
                    $next[] = [...$row, $key => $value];
                }
            }

            $rows = $next;
        }

        foreach ($matrix['exclude'] ?? [] as $exclude) {
            $rows = array_values(array_filter($rows, fn ($row) => count(array_intersect_assoc($row, $exclude)) !== count($exclude)));
        }

        $added = [];

        foreach ($matrix['include'] ?? [] as $include) {
            $matched = false;
            $originalValues = array_intersect_key($include, array_flip($keys));

            foreach ($rows as &$row) {
                if ($keys !== [] && count(array_intersect_assoc($row, $originalValues)) === count($originalValues)) {
                    $row = [...$row, ...$include];
                    $matched = true;
                }
            }

            unset($row);

            if (! $matched) {
                $added[] = $include;
            }
        }

        return array_values(array_filter(array_column([...$rows, ...$added], $match[1]), is_string(...)));
    }

    /** @param array<mixed> $job */
    private function needsCache(array $job): bool
    {
        $installs = ['composer' => false, 'npm' => false];
        $cached = ['composer' => false, 'npm' => false];
        $php = false;
        $composerCache = false;

        foreach ($job['steps'] ?? [] as $step) {
            $run = $step['run'] ?? '';

            if (is_string($run)) {
                $installs['composer'] = $installs['composer'] || preg_match('/\bcomposer\s+(?:install|update)\b/', $run) === 1;
                $installs['npm'] = $installs['npm'] || preg_match('/\bnpm\s+(?:ci|install|i)\b/', $run) === 1;
            }

            $uses = $step['uses'] ?? '';
            $with = $step['with'] ?? [];
            $php = $php || str_starts_with($uses, 'shivammathur/setup-php@');
            $cached['composer'] = $cached['composer'] || str_starts_with($uses, 'ramsey/composer-install@');
            $cached['npm'] = $cached['npm'] || (str_starts_with($uses, 'actions/setup-node@') && ($with['cache'] ?? '') === 'npm');

            if (preg_match('/^actions\/cache(?:\/restore)?@/', $uses) === 1) {
                $key = $with['key'] ?? '';
                $path = $with['path'] ?? '';
                $cached['composer'] = $cached['composer'] || (str_contains($key, 'hashFiles(') && str_contains($key, 'composer.lock'));
                $cached['npm'] = $cached['npm'] || (str_contains($key, 'hashFiles(') && str_contains($key, 'package-lock.json'));
                $composerCache = $composerCache || preg_match('/composer.*cache|cache.*composer/i', $path) === 1;
            }
        }

        return ($installs['composer'] && ! $cached['composer'] && ! ($php && $composerCache)) || ($installs['npm'] && ! $cached['npm']);
    }
}
