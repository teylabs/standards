<?php

declare(strict_types=1);

namespace Tey\Standards\Enforcement;

use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Tey\Standards\Patterns\KitExports;
use Tey\Standards\Patterns\Lookup;
use Tey\Standards\Patterns\TreeCatalog;

/** The offline CLI over the pattern library; hosted agents call bakery.find instead. */
final class PatternCommand extends Command
{
    public $signature = 'standards:pattern {query? : An intent or a project-relative path} {--answer=* : Answers from the root question, in order (number, text or next id)} {--recipe= : One leaf by id} {--gaps : Gap leaves, most demanded first} {--json : Write contract-1 JSON to stdout}';

    public $description = 'Find the feature plan, unit recipe and kit components before building (offline bakery.find)';

    public function handle(): int
    {
        if (($warning = KitExports::mismatch($this->laravel->basePath())) !== null) {
            fwrite(STDERR, 'Warning: '.$warning.PHP_EOL);
        }

        $lookup = new Lookup(TreeCatalog::shipped(), InstalledVersions::isInstalled(Lookup::RECIPE_PACKAGE));
        $query = $this->argument('query');
        $answers = (array) $this->option('answer');

        $result = match (true) {
            (bool) $this->option('gaps') => ['contract' => Lookup::CONTRACT, 'type' => 'gaps', 'leaves' => $lookup->gaps()],
            is_string($this->option('recipe')) => $lookup->recipe($this->option('recipe')),
            $answers !== [] => $lookup->find(['answers' => array_values($answers)]),
            is_string($query) && $query !== '' => $lookup->find(str_contains($query, '/') || preg_match('/\.(php|vue|ts|js)$/', $query) === 1 ? ['path' => $query] : ['intent' => $query]),
            default => $lookup->find(['answers' => []]),
        };

        // Traversals and searches that end on a byo or gap leaf record demand; reading a recipe by id does not.
        if (is_array($result['demand'] ?? null) && $this->option('recipe') === null) {
            GapDemand::append($this->laravel->basePath(), $result['demand']);
        }

        $this->line($this->option('json') ? json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : self::text($result));

        return $result['type'] === 'error' ? 2 : 0;
    }

    /** @param array<string, mixed> $result */
    public static function text(array $result): string
    {
        return match ($result['type']) {
            'error' => 'Error: '.$result['error'],
            'question' => self::questionText($result),
            'leaf' => self::leafText($result),
            'plan' => self::planText($result),
            'gaps' => implode("\n", ['Gap leaves (no recipe yet), most demanded first:', ...array_map(fn (array $leaf): string => '- '.$leaf['id'].self::demand($leaf['evidence']).': '.$leaf['summary']."\n  ".$leaf['message'], $result['leaves'])]),
        };
    }

    /** @param array<string, mixed> $question */
    private static function questionText(array $question): string
    {
        $lines = [];

        foreach ($question['candidates'] as $candidate) {
            $lines[] = "  candidate {$candidate['id']} [{$candidate['status']}]: {$candidate['summary']}";
        }

        $lines = [...($lines === [] ? [] : ['Candidates:', ...$lines, '']), "? {$question['question']}  ({$question['id']})"];

        if ($question['why'] !== null) {
            $lines[] = '  '.$question['why'];
        }

        foreach ($question['options'] as $index => $option) {
            $lines[] = sprintf('  %d. %s → %s', $index + 1, $option['answer'], $option['next']);
        }

        $flags = implode(' ', array_map(fn (string $answer): string => '--answer='.escapeshellarg($answer), $question['path']));
        $lines[] = '  Answer: php artisan standards:pattern '.($flags === '' ? '' : $flags.' ').'--answer=<number>';

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $leaf */
    private static function leafText(array $leaf): string
    {
        return implode("\n", array_filter([
            "■ {$leaf['id']} [{$leaf['status']}] {$leaf['recipe']}",
            '  '.$leaf['summary'],
            $leaf['preferred_make'] === null ? null : "  make ({$leaf['preferred_make_source']}): php artisan {$leaf['preferred_make']}",
            $leaf['recipe_make'] !== null && $leaf['preferred_make_source'] !== Lookup::RECIPE_PACKAGE ? '  with the Bakery installed: php artisan '.str_replace('{Name}', '<Name>', $leaf['recipe_make']) : null,
            $leaf['components'] === [] ? null : "  components:\n".implode("\n", array_map(fn (array $c): string => "    - {$c['kit_component']} ({$c['name']}): {$c['use']}; props: {$c['props_hint']}", $leaf['components'])),
            $leaf['checks'] === [] ? null : '  checks: '.implode(', ', $leaf['checks']),
            $leaf['message'],
            $leaf['siblings'] === [] ? null : '  nearest recipes: '.implode(', ', $leaf['siblings']),
            $leaf['conventions'] === null ? null : '  conventions: rules '.implode(', ', $leaf['conventions']['rules'])."\n".implode("\n", array_map(fn (string $note): string => '    - '.$note, $leaf['conventions']['notes'])),
        ]));
    }

    /** @param array<string, mixed> $plan */
    private static function planText(array $plan): string
    {
        $lines = ["Plan: {$plan['feature']['id']}: {$plan['feature']['summary']}"];

        foreach ($plan['units'] as $unit) {
            $lines[] = sprintf('  - %s%s: %s [%s] %s', $unit['role'], $unit['when'] === null ? '' : " (when {$unit['when']})", $unit['leaf'], $unit['status'],
                $unit['preferred_make'] === null ? ($unit['message'] ?? '') : 'php artisan '.$unit['preferred_make']);
        }

        foreach ($plan['questions'] as $question) {
            $lines[] = "? {$question['question']} ({$question['id']}: ".implode('|', $question['options']).')';
        }

        $lines[] = 'Each unit: php artisan standards:pattern --recipe=<leaf>';

        return implode("\n", $lines);
    }

    /** @param array<string, int>|null $evidence */
    private static function demand(?array $evidence): string
    {
        if ($evidence === null) {
            return '';
        }

        return " (fleet {$evidence['apps']} apps × {$evidence['count']}".(isset($evidence['past_apps']) ? ", past {$evidence['past_apps']} apps × {$evidence['past_count']}" : '').')';
    }
}
