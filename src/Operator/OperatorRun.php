<?php

declare(strict_types=1);

namespace Tey\Standards\Operator;

use Closure;
use InvalidArgumentException;
use Throwable;

/**
 * One run of an operator command: whether it writes, and its counts. A write goes through write(), which runs only when the run applies; counting is
 * the command's own (processed, then changed, skipped or failed for each item). A reason is a short label (`already set`, `missing owner`), never
 * record data: the summary prints reasons, so an id, an email or a secret must not be one.
 */
final class OperatorRun
{
    private int $processed = 0;

    private int $changed = 0;

    /** @var array<string, int> */
    private array $skipped = [];

    /** @var array<string, int> */
    private array $failed = [];

    public function __construct(private readonly bool $applying) {}

    public function applying(): bool
    {
        return $this->applying;
    }

    /** Runs the write when applying; in a dry run it does nothing and returns null. */
    public function write(Closure $write): mixed
    {
        return $this->applying ? $write() : null;
    }

    public function processed(int $count = 1): void
    {
        $this->processed += max(0, $count);
    }

    /** An item that was changed, or in a dry run would be. */
    public function changed(int $count = 1): void
    {
        $this->changed += max(0, $count);
    }

    /** An item left as it was: `already done` is the idempotent re-run's reason. */
    public function skipped(string $reason, int $count = 1): void
    {
        $this->skipped[self::label($reason)] = ($this->skipped[self::label($reason)] ?? 0) + max(0, $count);
    }

    public function failed(string $reason, int $count = 1): void
    {
        $this->failed[self::label($reason)] = ($this->failed[self::label($reason)] ?? 0) + max(0, $count);
    }

    /**
     * Each item in its own try: one that throws is counted as failed (by the exception's class, never its message) and the rest carry on.
     *
     * @param  iterable<mixed>  $items
     * @param  Closure(mixed, self): void  $each
     */
    public function each(iterable $items, Closure $each): void
    {
        foreach ($items as $item) {
            $this->processed();
            try {
                $each($item, $this);
            } catch (Throwable $error) {
                $this->failed('exception '.strtolower((string) preg_replace('/(?<!^)[A-Z]/', ' $0', (new \ReflectionClass($error))->getShortName())));
            }
        }
    }

    /** @return array{processed: int, changed: int, skipped: int, failed: int, skipped_by: array<string, int>, failed_by: array<string, int>} */
    public function counts(): array
    {
        return ['processed' => $this->processed, 'changed' => $this->changed, 'skipped' => array_sum($this->skipped), 'failed' => array_sum($this->failed), 'skipped_by' => $this->skipped, 'failed_by' => $this->failed];
    }

    private static function label(string $reason): string
    {
        $reason = strtolower(trim($reason));
        if (preg_match('/\A[a-z][a-z0-9 _-]{0,47}\z/', $reason) !== 1) {
            throw new InvalidArgumentException('An operator reason is a short lowercase label (letters, digits, spaces), never record data.');
        }

        return $reason;
    }
}
