<?php

declare(strict_types=1);

namespace Tey\Standards\Preflight;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Log\LogManager;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Tey\Standards\Preflight;

final class PreflightCommand extends Command
{
    public $signature = 'standards:preflight {--only= : Run checks in a dotted group (e.g. mail)} {--strict : Exit 1 on any failed check, regardless of level} {--ci : Skip checks requiring production secrets} {--skip= : Comma-separated exact check names} {--json : Write machine-readable JSON to stdout} {--stderr : Also write the summary and failed or warning checks to stderr} {--no-stderr : Disable automatic stderr reporting (takes precedence over --stderr)}';

    public $description = 'Check deployment configuration and queue backlog without hosting API calls';

    public function handle(Preflight $registry, Repository $config, Filesystem $files, Runner $runner, LogManager $logs): int
    {
        $logger = $logs->channel($config->get('logging.channels.standards') !== null ? 'standards' : null);

        try {
            $checks = $registry->checks($this->laravel);

            if ($this->option('only') !== null) {
                $prefix = $this->option('only').'.';
                $checks = array_filter($checks, fn (Check $check): bool => str_starts_with($check->name(), $prefix));

                if ($checks === []) {
                    throw new InvalidArgumentException('Unknown check group: '.$this->option('only'));
                }
            }

            $environmentSkip = getenv('PREFLIGHT_SKIP');
            $environmentSkip = $environmentSkip === false ? (string) $config->get('preflight.skip', '') : $environmentSkip;
            $requested = array_values(array_unique(array_filter(array_map('trim', explode(',', $environmentSkip.','.$this->option('skip'))), fn (string $name): bool => $name !== '')));
            $unknown = array_diff($requested, array_keys($checks));

            if ($unknown !== []) {
                throw new InvalidArgumentException('Unknown skip names: '.implode(', ', $unknown));
            }

            $timeout = $config->get('preflight.timeout', 5);

            if (! is_int($timeout) || $timeout < 1 || ! function_exists('pcntl_alarm') || ! function_exists('pcntl_async_signals')) {
                throw new InvalidArgumentException('Enable PHP CLI PCNTL and set preflight.timeout to a positive integer in seconds.');
            }
        } catch (InvalidArgumentException $error) {
            if ($this->option('json')) {
                $this->line(json_encode(['schema' => 1, 'results' => [], 'exit_code' => 2, 'error' => $error->getMessage()], JSON_THROW_ON_ERROR));
            } else {
                $this->error($error->getMessage());
            }

            $this->report([], 2, $logger, error: $error->getMessage());

            return 2;
        }

        $records = new SkipRecords($this->laravel->storagePath('framework/preflight'), $this->laravel->basePath(), $files);
        $skipContexts = [];
        $results = [];
        $exit = 0;

        foreach ($checks as $name => $check) {
            $level = $check->level();

            if (in_array($name, $requested, true)) {
                $reason = 'Emergency skip requested by '.(in_array($name, array_map('trim', explode(',', (string) $this->option('skip'))), true) ? '--skip' : 'PREFLIGHT_SKIP').'.';
                $this->banner($name, $reason);
                $skipContexts[$name] = $records->persist($name, $reason);
                $result = new Result(Status::SkippedByRequest, $reason);
            } else {
                $result = $runner->run($check, $timeout, (bool) $this->option('ci'));

                if ($result->status === Status::Pass) {
                    $records->clear($name);
                }
            }

            if ($result->status === Status::Fail && ($level === Level::Blocking || $this->option('strict'))) {
                $exit = 1;
            }

            $results[] = ['check' => $name, 'level' => $level->value, 'status' => $result->status->value, 'message' => $result->message];
        }

        if ($this->option('json')) {
            $this->line(json_encode(['schema' => 1, 'results' => $results, 'exit_code' => $exit], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            $this->table(['Check', 'Level', 'Result', 'Message'], $results);
        }

        $this->report($results, $exit, $logger, $skipContexts);

        return $exit;
    }

    /**
     * @param  list<array{check: string, level: string, status: string, message: string}>  $results
     * @param  array<string, array{check: string, reason: string, time: string, commit: ?string}>  $skipContexts
     */
    private function report(array $results, int $exit, LoggerInterface $logger, array $skipContexts = [], ?string $error = null): void
    {
        $counts = array_fill_keys(array_column(Status::cases(), 'value'), 0);
        $skips = [];
        $stderr = ! $this->option('no-stderr') && ($this->option('stderr') || ! stream_isatty(STDOUT));
        $output = $this->output->getOutput();
        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : (new ConsoleOutput)->getErrorOutput();

        foreach ($results as $result) {
            $status = Status::from($result['status']);
            $counts[$status->value]++;

            if (in_array($status, [Status::Skipped, Status::SkippedInCi, Status::SkippedByRequest], true)) {
                $skips[] = $result['check'];
            }

            $label = match ($status) {
                Status::Fail => 'FAILED',
                Status::Warn => 'WARNING',
                Status::Skipped, Status::SkippedInCi, Status::SkippedByRequest => 'SKIPPED',
                Status::Pass => 'PASSED',
            };
            $message = 'PREFLIGHT CHECK '.$label.': '.$result['check'];
            $logger->log(in_array($status, [Status::Fail, Status::Warn, Status::SkippedByRequest], true) ? 'warning' : 'info', $message, [...($skipContexts[$result['check']] ?? []), ...$result]);

            if ($stderr && in_array($status, [Status::Fail, Status::Warn], true)) {
                $errorOutput->writeln($message.' '.json_encode($result, JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
            }
        }

        $summary = ['exit_code' => $exit, 'counts' => $counts, 'skips' => $skips, 'ci' => (bool) $this->option('ci'), 'strict' => (bool) $this->option('strict'), 'only' => $this->option('only')];

        if ($error !== null) {
            $summary['error'] = $error;
        }

        $logger->info('PREFLIGHT SUMMARY', $summary);

        if ($stderr) {
            $errorOutput->writeln('PREFLIGHT SUMMARY '.json_encode($summary, JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        }
    }

    private function banner(string $check, string $reason): void
    {
        $output = $this->output->getOutput();

        if ($this->option('json')) {
            $output = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : (new ConsoleOutput)->getErrorOutput();
        }

        $output->writeln('<error>!!! PREFLIGHT CHECK SKIPPED: '.$check.' — '.$reason.' !!!</error>');
    }
}
