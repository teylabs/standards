<?php

declare(strict_types=1);

namespace Tey\Standards\Enforcement;

use Closure;
use Symfony\Component\Process\Process;

/**
 * Claude Code hooks for an app that requires tey/standards. Each event is a
 * no-op anywhere else. Commit and Stop block on drift; the new-file and
 * after-edit hooks only add context, apart from one soft block that asks for
 * a pattern stamp and then lets the write through.
 */
final class Hook
{
    public const EVENTS = ['stop', 'pre-commit', 'pre-write', 'post-edit'];

    public const STAMP = 'standards-pattern:';

    private const NEW_FILE_ROOTS = ['app/', 'resources/js/'];

    /** @var Closure(list<string>): array{exit: int, output: string} */
    private readonly Closure $artisan;

    /** @param (Closure(list<string>): array{exit: int, output: string})|null $artisan */
    public function __construct(private readonly string $base, ?Closure $artisan = null, private readonly ?string $stateDirectory = null)
    {
        $this->artisan = $artisan ?? function (array $arguments): array {
            $process = new Process([PHP_BINARY, 'artisan', ...$arguments], $this->base, timeout: 300);
            $process->run();

            return ['exit' => (int) $process->getExitCode(), 'output' => $process->getOutput()];
        };
    }

    /** A Laravel app (artisan present) whose composer.json requires tey/standards. */
    public static function applies(string $base): bool
    {
        if (! is_file($base.'/artisan') || ! is_file($base.'/composer.json')) {
            return false;
        }

        $composer = json_decode((string) file_get_contents($base.'/composer.json'), true);

        return is_array($composer) && (isset($composer['require']['tey/standards']) || isset($composer['require-dev']['tey/standards']));
    }

    /**
     * @param  array<string, mixed>  $input  the hook's stdin JSON
     * @return array{exit: int, stdout: string, stderr: string}
     */
    public function handle(string $event, array $input): array
    {
        $pass = ['exit' => 0, 'stdout' => '', 'stderr' => ''];

        if (! self::applies($this->base)) {
            return $pass;
        }

        return match ($event) {
            'stop' => ($input['stop_hook_active'] ?? false) === true ? $pass : $this->stop(),
            'pre-commit' => $this->preCommit((string) ($input['tool_input']['command'] ?? '')),
            'pre-write' => $this->preWrite($input),
            'post-edit' => $this->postEdit((string) ($input['tool_input']['file_path'] ?? '')),
            default => $pass,
        };
    }

    /** @return array{exit: int, stdout: string, stderr: string} */
    private function stop(): array
    {
        $failures = $this->check(['--changed']);

        return $failures === null
            ? ['exit' => 0, 'stdout' => '', 'stderr' => '']
            : ['exit' => 0, 'stdout' => self::json(['decision' => 'block', 'reason' => "standards:check --changed found drift. Fix it before finishing:\n".$failures]), 'stderr' => ''];
    }

    /** @return array{exit: int, stdout: string, stderr: string} */
    private function preCommit(string $command): array
    {
        if (preg_match('/(^|[\s;&|(])git(\s+-[^\s]+(\s+[^\s-][^\s]*)?)*\s+commit\b/', $command) !== 1) {
            return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
        }

        $failures = $this->check(['--changed']);

        return $failures === null
            ? ['exit' => 0, 'stdout' => '', 'stderr' => '']
            : ['exit' => 2, 'stdout' => '', 'stderr' => "Commit blocked: standards:check --changed found drift.\n".$failures];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function preWrite(array $input): array
    {
        $path = $this->relative((string) ($input['tool_input']['file_path'] ?? ''));
        $content = (string) ($input['tool_input']['content'] ?? '');

        if ($path === null || is_file($this->base.'/'.$path) || ! $this->underNewFileRoot($path)) {
            return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
        }

        $lookup = $this->lookup($path);
        $stamp = preg_match('/'.preg_quote(self::STAMP, '/').'\s*([a-z0-9.-]+)/', $content, $match) === 1 ? $match[1] : null;

        if ($stamp === null && $this->firstAttempt($input, $path)) {
            return ['exit' => 0, 'stdout' => self::json(['hookSpecificOutput' => [
                'hookEventName' => 'PreToolUse',
                'permissionDecision' => 'deny',
                'permissionDecisionReason' => "New file {$path} has no pattern stamp. Traverse before you build: call bakery.find (offline: `php artisan standards:pattern`), pick the leaf below, prefer its make command, or add a comment `".self::STAMP." <leaf-id>` (or `".self::STAMP." gap`) to the file and write it again. This asks once; the next attempt goes through.\n".$lookup,
            ]]), 'stderr' => ''];
        }

        return ['exit' => 0, 'stdout' => self::json(['hookSpecificOutput' => [
            'hookEventName' => 'PreToolUse',
            'additionalContext' => "Pattern lookup for new file {$path}:\n".$lookup,
        ]]), 'stderr' => ''];
    }

    /** @return array{exit: int, stdout: string, stderr: string} */
    private function postEdit(string $file): array
    {
        $path = $this->relative($file);

        if ($path === null) {
            return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
        }

        $failures = $this->check(['--file='.$path]);

        return $failures === null
            ? ['exit' => 0, 'stdout' => '', 'stderr' => '']
            : ['exit' => 0, 'stdout' => self::json(['hookSpecificOutput' => ['hookEventName' => 'PostToolUse', 'additionalContext' => "standards:check --file={$path} (advisory; commit and Stop will block):\n".$failures]]), 'stderr' => ''];
    }

    /** @param list<string> $options */
    private function check(array $options): ?string
    {
        $result = ($this->artisan)(['standards:check', ...$options, '--json']);
        $report = json_decode($result['output'], true);

        if (! is_array($report)) {
            return $result['exit'] === 0 ? null : 'standards:check exited '.$result['exit'].' without a report: '.trim($result['output']);
        }

        if ($report['violations'] === [] && $result['exit'] === 0) {
            return null;
        }

        $lines = array_map(fn (array $v): string => "- [{$v['rule']}] {$v['kind']}: {$v['target']} — {$v['check']}\n  fix: {$v['hint']}", $report['violations']);

        return $lines === [] ? 'standards:check exited '.$result['exit'].'.' : implode("\n", $lines);
    }

    private function lookup(string $path): string
    {
        $result = ($this->artisan)(['standards:pattern', $path, '--json']);
        $lookup = json_decode($result['output'], true);

        if (! is_array($lookup) || ! in_array($lookup['type'] ?? null, ['question', 'leaf'], true)) {
            return 'Pattern lookup unavailable here; call bakery.find, or run `php artisan standards:pattern` to traverse from the root question.';
        }

        $lines = [];
        $leaves = $lookup['type'] === 'leaf' ? [$lookup] : $lookup['candidates'];

        foreach ($leaves as $leaf) {
            $lines[] = "- leaf {$leaf['id']} ({$leaf['status']}): {$leaf['summary']}".(($leaf['preferred_make'] ?? null) !== null ? "\n  make ({$leaf['preferred_make_source']}): php artisan {$leaf['preferred_make']}" : (($leaf['make'] ?? null) !== null ? "\n  make: php artisan {$leaf['make']} <Name>" : '')).(($leaf['message'] ?? null) !== null ? "\n  ".$leaf['message'] : '');
        }

        $question = $lookup['type'] === 'question' ? $lookup : $lookup['next_question'];

        if ($question !== null) {
            $answers = isset($question['options']) ? ' '.implode(' | ', array_map(fn (array $o): string => $o['answer'].' → '.$o['next'], $question['options'])) : '';
            $lines[] = "- next question ({$question['id']}): {$question['question']}{$answers}";
        }

        $lines[] = 'More detail: bakery.recipe <leaf>, or `php artisan standards:pattern --recipe=<leaf>`.';

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $input */
    private function firstAttempt(array $input, string $path): bool
    {
        $directory = $this->stateDirectory ?? sys_get_temp_dir().'/tey-standards-hooks';
        $marker = $directory.'/'.hash('sha256', $this->base.'|'.($input['session_id'] ?? '').'|'.$path);

        if (is_file($marker)) {
            return false;
        }

        if (! is_dir($directory)) {
            @mkdir($directory, 0700, true);
        }

        // If the marker cannot be written, let the write through rather than block forever.
        return @file_put_contents($marker, (string) time()) !== false;
    }

    private function underNewFileRoot(string $path): bool
    {
        foreach (self::NEW_FILE_ROOTS as $root) {
            if (str_starts_with($path, $root)) {
                return true;
            }
        }

        return false;
    }

    private function relative(string $file): ?string
    {
        if ($file === '') {
            return null;
        }

        $file = str_replace('\\', '/', $file);
        $base = rtrim(str_replace('\\', '/', $this->base), '/').'/';

        if (str_starts_with($file, $base)) {
            return substr($file, strlen($base));
        }

        return str_starts_with($file, '/') || preg_match('/^[A-Za-z]:/', $file) === 1 ? null : (string) preg_replace('#^(\./)+#', '', $file);
    }

    /** @param array<string, mixed> $payload */
    private static function json(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
