<?php

declare(strict_types=1);

namespace Tey\Standards\Enforcement;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Tey\Standards\Patterns\Lookup;
use Tey\Standards\Patterns\TreeCatalog;

final class PatternCommand extends Command
{
    public $signature = 'standards:pattern {query? : An intent, a project-relative path or a node id} {--node= : Step mode: the node to answer} {--answer= : Step mode: an option number, its text or its next id} {--gaps : List leaves with no recipe yet} {--json : Write JSON to stdout}';

    public $description = 'Traverse the pattern decision tree, or match an intent or path to its recipe and generator';

    public function handle(): int
    {
        $lookup = new Lookup(TreeCatalog::shipped(), Lookup::recipesInstalled());

        try {
            $query = $this->argument('query');
            $result = match (true) {
                (bool) $this->option('gaps') => ['type' => 'gaps', 'leaves' => $lookup->gaps()],
                is_string($query) && $query !== '' && $this->option('node') === null => $lookup->intent($query),
                default => $lookup->step($this->option('node'), $this->option('answer')),
            };
        } catch (InvalidArgumentException $error) {
            $this->error($error->getMessage());

            return 2;
        }

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return 0;
        }

        $this->line(self::text($result));

        return 0;
    }

    /** @param array<string, mixed> $result */
    public static function text(array $result): string
    {
        return match ($result['type']) {
            'question' => self::questionText($result),
            'leaf' => self::leafText($result),
            'gaps' => implode("\n", ['Gap leaves (no recipe yet), most evidenced first:', ...array_map(fn (array $leaf): string => "- {$leaf['id']}".($leaf['evidence'] === null ? '' : " ({$leaf['evidence']['apps']} apps × {$leaf['evidence']['count']} changes)").": {$leaf['summary']}\n  {$leaf['message']}", $result['leaves'])]),
            default => implode("\n\n", [
                $result['candidates'] === [] ? "No leaf matches \"{$result['query']}\"; start from the root question." : 'Candidates for "'.$result['query'].'":',
                ...array_map(self::leafText(...), $result['candidates']),
                ...array_map(self::questionText(...), $result['questions']),
            ]),
        };
    }

    /** @param array<string, mixed> $question */
    private static function questionText(array $question): string
    {
        $lines = ["? {$question['question']}  ({$question['id']})"];

        if (isset($question['why'])) {
            $lines[] = '  '.$question['why'];
        }

        foreach ($question['options'] as $index => $option) {
            $lines[] = sprintf('  %d. %s → %s', $index + 1, $option['answer'], $option['next']);
        }

        $lines[] = "  Answer: php artisan standards:pattern --node={$question['id']} --answer=<number>";

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $leaf */
    private static function leafText(array $leaf): string
    {
        return implode("\n", array_filter([
            "■ {$leaf['id']} [{$leaf['status']}] {$leaf['recipe']}",
            '  '.$leaf['summary'],
            $leaf['kit'] === [] ? null : '  kit: '.implode(', ', $leaf['kit']),
            $leaf['preferred_make'] === null ? null : "  make ({$leaf['preferred_make_source']}): php artisan {$leaf['preferred_make']}",
            $leaf['recipe_make'] !== null && $leaf['preferred_make'] !== str_replace('{Name}', '<Name>', $leaf['recipe_make']) ? "  with the Bakery installed: php artisan ".str_replace('{Name}', '<Name>', $leaf['recipe_make']) : null,
            $leaf['checks'] === [] ? null : '  checks: '.implode(', ', $leaf['checks']),
            isset($leaf['message']) ? '  '.$leaf['message'] : null,
        ]));
    }
}
