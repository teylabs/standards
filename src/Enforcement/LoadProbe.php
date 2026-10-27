<?php

declare(strict_types=1);

namespace Tey\Standards\Enforcement;

use Symfony\Component\Process\Process;

/**
 * Loads every class in the class roots in a separate PHP process, so a class
 * that fatals on load (a missing abstract method, a parent that doesn't exist)
 * becomes a finding instead of crashing the check. After a fatal the probe
 * restarts with the next class.
 */
final class LoadProbe
{
    /**
     * @param  list<string>  $classes
     * @return array<string, string> class => why it does not load
     */
    public static function failures(string $base, string $autoload, array $classes): array
    {
        $failures = [];
        $remaining = array_values($classes);

        while ($remaining !== []) {
            $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/resources/load-probe.php', $autoload], $base, timeout: 300);
            $process->setInput(implode("\n", $remaining)."\n");
            $process->run();
            $current = null;
            $done = [];

            foreach (preg_split('/\R/', $process->getOutput()) ?: [] as $line) {
                if (preg_match('/^(BEGIN|OK|ERR) (\S+)(?: (.*))?$/', $line, $match) !== 1) {
                    continue;
                }

                [$kind, $class] = [$match[1], $match[2]];

                if ($kind === 'BEGIN') {
                    $current = $class;

                    continue;
                }

                $done[$class] = true;
                $current = null;

                if ($kind === 'ERR') {
                    $failures[$class] = self::clean($match[3] ?? 'failed to load', $base);
                }
            }

            if ($current === null) {
                break;
            }

            // The process died while loading $current: a fatal error.
            $failures[$current] = self::clean(self::fatal($process->getOutput().$process->getErrorOutput()) ?? 'fatal error while loading (exit '.$process->getExitCode().')', $base);
            $index = array_search($current, $remaining, true);
            $remaining = array_slice($remaining, (int) $index + 1);
        }

        return $failures;
    }

    private static function fatal(string $output): ?string
    {
        return preg_match('/(?:PHP )?Fatal error:\s*(.+?)(?: in \S+ on line \d+|$)/m', $output, $match) === 1 ? trim($match[1]) : null;
    }

    /** Keep messages project-relative: no absolute paths. */
    private static function clean(string $message, string $base): string
    {
        return trim(str_replace([rtrim($base, '/\\').'/', rtrim($base, '/\\').'\\', rtrim($base, '/\\')], '', $message));
    }
}
