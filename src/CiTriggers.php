<?php

declare(strict_types=1);

namespace Tey\Standards;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final readonly class CiTriggers
{
    /** @param list<string> $defaultBranches */
    public function __construct(private string $base, private array $defaultBranches = ['main'], private ?bool $package = null) {}

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
        $findings = [];

        foreach ($this->workflows() as $path) {
            $file = substr($path, strlen(rtrim($this->base, '/')) + 1);

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

            foreach ($this->inspect($workflow) as $finding) {
                $findings[] = ['workflow' => $file, ...$finding];
            }
        }

        return $findings;
    }

    /**
     * @param  array<mixed>  $workflow
     * @return list<array{job: string, reason: string}>
     */
    private function inspect(array $workflow): array
    {
        $events = $workflow['on'] ?? $workflow[true] ?? [];
        $events = is_string($events) ? [$events => null] : (is_array($events) ? $events : []);
        $events = array_is_list($events) ? array_fill_keys($events, null) : $events;
        $jobs = $workflow['jobs'] ?? [];
        $findings = [];

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

            if ($pullRequest && $guard && ! in_array('ready_for_review', $types, true)) {
                $findings[] = ['job' => $name, 'reason' => 'pull-request-types: include ready_for_review so draft-skipping jobs restart.'];
            }

            $concurrency = $job['concurrency'] ?? $workflow['concurrency'] ?? null;

            if ($concurrency === null || $concurrency === [] || $concurrency === '') {
                $findings[] = ['job' => $name, 'reason' => 'concurrency: set workflow concurrency or concurrency on every job.'];
            }

            if ($pullRequest && ! $this->excludesPullRequest($job['if'] ?? '')) {
                if ($this->tests($job) && ! $guard) {
                    $findings[] = ['job' => $name, 'reason' => 'draft-guard: skip test jobs while github.event.pull_request.draft is true.'];
                }

                $matrix = $job['strategy']['matrix'] ?? [];

                if (! ($this->package ?? ! is_file($this->base.'/artisan')) && $this->multiplePhpVersions($matrix)) {
                    $findings[] = ['job' => $name, 'reason' => 'php-matrix: run one deploy PHP version on pull_request; select extra versions by github.event_name.'];
                }

                if ($this->nonLinux($job['runs-on'] ?? '', $matrix)) {
                    $findings[] = ['job' => $name, 'reason' => 'os-matrix: use Linux on pull_request; select other OS legs by github.event_name.'];
                }
            }

            if (! isset($job['timeout-minutes'])) {
                $findings[] = ['job' => $name, 'reason' => 'timeout: set timeout-minutes on every job.'];
            }
        }

        foreach (['workflow' => $workflow['concurrency'] ?? null, ...array_map(fn (mixed $job): mixed => is_array($job) ? ($job['concurrency'] ?? null) : null, $jobs)] as $name => $concurrency) {
            $cancel = is_array($concurrency) ? ($concurrency['cancel-in-progress'] ?? false) : false;

            if ($cancel === true || (is_string($cancel) && preg_match('/^(?:true|\$\{\{\s*true\s*\}\})$/i', trim($cancel)) === 1)) {
                $findings[] = ['job' => (string) $name, 'reason' => 'concurrency: condition cancel-in-progress on pull_request so push/main runs finish.'];
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

            if (is_string($run) && preg_match('/(?:\bphp\s+artisan\s+test\b|(?:^|[\s\/;&|])(?:pest|phpunit|vitest)(?:\s|$)|\bnpm\s+test\b|\bnpx\s+playwright\b)/m', $run) === 1) {
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
        if (is_string($runner) && str_contains($runner, 'github.event_name')) {
            return false;
        }

        if (is_string($runner) && preg_match('/matrix\.([\w-]+)/', $runner, $match) === 1) {
            $runner = is_array($matrix) ? ($matrix[$match[1]] ?? []) : $matrix;

            if (is_array($runner) && is_array($matrix)) {
                $runner = [...$runner, ...array_column($matrix['include'] ?? [], $match[1])];
            }

            if (is_string($runner) && str_contains($runner, 'github.event_name')) {
                return false;
            }
        }

        if (is_string($runner) && str_contains($runner, '${{')) {
            return preg_match('/(?:windows|macos)/i', $runner) === 1;
        }

        $labels = is_array($runner) ? $runner : [$runner];

        return preg_match('/(?:windows|macos)/i', implode(' ', $labels)) === 1
            || ($labels !== [] && preg_match('/(?:ubuntu|linux)/i', implode(' ', $labels)) !== 1);
    }
}
