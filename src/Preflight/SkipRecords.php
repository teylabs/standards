<?php

declare(strict_types=1);

namespace Tey\Standards\Preflight;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Throwable;

final readonly class SkipRecords
{
    public function __construct(private string $directory, private string $base, private Filesystem $files) {}

    /** @return array{check: string, reason: string, time: string, commit: ?string} */
    public function persist(string $check, string $reason): array
    {
        $record = [
            'check' => $check,
            'reason' => $reason,
            'time' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
            'commit' => $this->commit(),
        ];
        $this->files->ensureDirectoryExists($this->directory);
        $this->files->replace($this->directory.'/'.$check.'.json', json_encode($record, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n");

        return $record;
    }

    public function clear(string $check): void
    {
        if ($this->files->exists($this->directory.'/'.$check.'.json') && ! $this->files->delete($this->directory.'/'.$check.'.json')) {
            throw new \RuntimeException('Cannot clear preflight skip record.');
        }
    }

    private function commit(): ?string
    {
        try {
            $process = new Process(['git', 'rev-parse', 'HEAD'], $this->base, timeout: 1);
            $process->run();
            $commit = trim($process->getOutput());

            return $process->isSuccessful() && preg_match('/\A[0-9a-f]{40,64}\z/', $commit) ? $commit : null;
        } catch (Throwable) {
            return null;
        }
    }
}
