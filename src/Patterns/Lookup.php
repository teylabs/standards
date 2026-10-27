<?php

declare(strict_types=1);

namespace Tey\Standards\Patterns;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The traversal engine behind standards:pattern and any host (such as a
 * hosted MCP) that answers "what should I build with?". It is framework-light:
 * plain arrays in and out, shaped by contract 1 (docs/pattern-contract.md).
 *
 *  - find(['answers' => [...]]) walks from the root question.
 *  - find(['intent' => '...']) or find(['path' => '...']) matches leaves.
 *  - recipe($id) returns one leaf.
 *
 * Every path ends in a leaf. Byo and gap leaves return the nearest recipes,
 * the general conventions, a note to proceed and an anonymised demand record
 * that the caller may store.
 */
final class Lookup
{
    public const CONTRACT = 1;

    /** The package that runs recipe_make commands; when installed its recipe is preferred. */
    public const RECIPE_PACKAGE = 'butter/bakery';

    public const NOTES = [
        'Writes live in one Action that controllers, jobs and MCP tools share; controllers stay CRUDDY and thin.',
        'Pages receive typed ViewModels; classes follow the configured placement.',
        'UI comes from kit components imported through the kit package; never hand-roll a kit primitive.',
        'Outcomes use toasts; mail goes through Notifications.',
        'Stamp new files `standards-pattern: <leaf-id>`, or `standards-pattern: gap` when no recipe fits.',
        'Run `php artisan standards:check --changed` before committing.',
    ];

    public function __construct(
        private readonly PatternCatalog $catalog,
        private readonly bool $recipes = false,
        private readonly ?DateTimeImmutable $now = null,
    ) {}

    /**
     * @param  array{intent?: string, path?: string, answers?: list<string>}  $query
     * @return array<string, mixed>
     */
    public function find(array $query): array
    {
        try {
            if (isset($query['answers'])) {
                return $this->walk($query['answers']);
            }

            if (is_string($query['path'] ?? null) && trim($query['path']) !== '') {
                return $this->match($query['path'], 'path');
            }

            if (is_string($query['intent'] ?? null) && trim($query['intent']) !== '') {
                $feature = $this->feature($query['intent']);

                return $feature === null ? $this->match($query['intent'], 'intent') : $this->plan($feature);
            }

            return $this->walk([]);
        } catch (InvalidArgumentException $error) {
            return self::error($error->getMessage());
        }
    }

    /** The best feature for an intent: an exact id, or at least one whole keyword phrase. */
    public function feature(string $intent): ?Feature
    {
        $intent = strtolower(trim($intent));
        $best = null;
        $bestScore = 0;

        foreach ($this->catalog->features() as $feature) {
            if ($feature->id === $intent) {
                return $feature;
            }

            $phrases = count(array_filter($feature->keywords, fn (string $keyword): bool => str_contains($intent, strtolower($keyword))));
            $score = $phrases * 3 + count(array_intersect(self::words($intent), self::words(implode(' ', [$feature->summary, ...$feature->keywords]))));

            if ($phrases > 0 && $score > $bestScore) {
                [$best, $bestScore] = [$feature, $score];
            }
        }

        return $best;
    }

    /**
     * A feature's build plan: every unit with its recipe and make command.
     * Units without a recipe stay in the plan as gap or byo entries with
     * their nearest recipes, so a plan is never empty.
     *
     * @return array<string, mixed>
     */
    public function plan(Feature $feature): array
    {
        $units = [];

        foreach ($feature->composition as $unit) {
            $leaf = $this->catalog->node($unit['unit_leaf_id']);
            assert($leaf instanceof Leaf);
            $detail = $this->leaf($leaf, $this->pathTo($leaf->id), []);
            $units[] = [
                'leaf' => $leaf->id,
                'recipe' => $leaf->recipe,
                'make' => $leaf->make,
                'role' => $unit['role'],
                'when' => $unit['when'] ?? null,
                'status' => $leaf->status,
                'recipe_make' => $leaf->recipeMake,
                'preferred_make' => $detail['preferred_make'],
                'siblings' => $detail['siblings'],
                'message' => $detail['message'],
            ];
        }

        return [
            'contract' => self::CONTRACT,
            'type' => 'plan',
            'feature' => ['id' => $feature->id, 'summary' => $feature->summary],
            'units' => $units,
            'questions' => $feature->questions,
        ];
    }

    /** @return array<string, mixed> */
    public function recipe(string $id): array
    {
        $node = $this->catalog->node($id);

        if (! $node instanceof Leaf) {
            return self::error("Unknown leaf [{$id}].");
        }

        return $this->leaf($node, $this->pathTo($id), []);
    }

    /** @return list<array<string, mixed>> gap leaves, most evidenced (apps × count) first */
    public function gaps(): array
    {
        $gaps = array_values(array_filter($this->catalog->leaves(), fn (Leaf $leaf): bool => $leaf->status === 'gap'));
        usort($gaps, fn (Leaf $a, Leaf $b): int => [$b->weight(), $a->id] <=> [$a->weight(), $b->id]);

        return array_map(fn (Leaf $leaf): array => $this->recipe($leaf->id), $gaps);
    }

    /** @param list<string> $answers */
    private function walk(array $answers): array
    {
        $node = $this->catalog->root();
        $taken = [];

        foreach ($answers as $answer) {
            if (! $node instanceof Branch) {
                throw new InvalidArgumentException("{$node->id} is a leaf; there is nothing left to answer.");
            }

            $option = $this->option($node, (string) $answer);
            $taken[] = $option['answer'];
            $node = $this->catalog->node($option['next']);
        }

        return $node instanceof Leaf
            ? $this->leaf($node, $taken, self::criteria($taken, $this->vocabulary()))
            : $this->question($node, $taken, []);
    }

    /** @return array<string, mixed> */
    private function match(string $query, string $mode): array
    {
        $exact = $this->catalog->node($query);

        if ($exact !== null) {
            return $exact instanceof Leaf ? $this->leaf($exact, $this->pathTo($exact->id), []) : $this->question($exact, $this->pathTo($exact->id), []);
        }

        $candidates = array_slice($mode === 'path' ? $this->catalog->forPath($query) : $this->catalog->search($query), 0, 5);

        if (count($candidates) === 1) {
            return $this->leaf($candidates[0], $this->pathTo($candidates[0]->id), []);
        }

        if ($candidates === []) {
            return $this->question($this->catalog->root(), [], []);
        }

        $parent = $this->catalog->parentOf($candidates[0]->id) ?? $this->catalog->root();

        return $this->question($parent, $this->pathTo($parent->id), $candidates);
    }

    /** @return array{answer: string, next: string} */
    private function option(Branch $branch, string $answer): array
    {
        foreach ($branch->options as $index => $option) {
            if ($answer === (string) ($index + 1) || strcasecmp(trim($answer), $option['answer']) === 0 || $answer === $option['next']) {
                return $option;
            }
        }

        throw new InvalidArgumentException("[{$answer}] is not an answer to {$branch->id}; answer with an option number, its text or its next id.");
    }

    /**
     * @param  list<string>  $path
     * @param  list<Leaf>  $candidates
     * @return array<string, mixed>
     */
    private function question(Branch $branch, array $path, array $candidates): array
    {
        return [
            'contract' => self::CONTRACT,
            'type' => 'question',
            'id' => $branch->id,
            'question' => $branch->question,
            'why' => $branch->why,
            'options' => array_map(fn (array $option): array => ['answer' => $option['answer'], 'next' => $option['next']], $branch->options),
            'path' => $path,
            'candidates' => array_map(fn (Leaf $leaf): array => ['id' => $leaf->id, 'status' => $leaf->status, 'summary' => $leaf->summary, 'make' => $leaf->make], $candidates),
        ];
    }

    /**
     * @param  list<string>  $path
     * @param  list<string>  $criteria
     * @return array<string, mixed>
     */
    private function leaf(Leaf $leaf, array $path, array $criteria): array
    {
        $parent = $this->catalog->parentOf($leaf->id);
        $open = in_array($leaf->status, ['gap', 'byo'], true);
        $preferRecipe = $this->recipes && $leaf->recipeMake !== null;
        $siblings = $open ? $this->siblings($leaf, $criteria) : [];

        return [
            'contract' => self::CONTRACT,
            'type' => 'leaf',
            'id' => $leaf->id,
            'status' => $leaf->status,
            'recipe' => $leaf->recipe,
            'summary' => $leaf->summary,
            'kit' => $leaf->kit,
            'make' => $leaf->make,
            'recipe_make' => $leaf->recipeMake,
            'preferred_make' => $preferRecipe ? str_replace('{Name}', '<Name>', (string) $leaf->recipeMake) : ($leaf->make === null ? null : $leaf->make.' <Name>'),
            'preferred_make_source' => $preferRecipe ? self::RECIPE_PACKAGE : ($leaf->make === null ? null : 'tey/standards'),
            'checks' => $leaf->checks,
            'paths' => $leaf->paths,
            'keywords' => $leaf->keywords,
            'evidence' => $leaf->evidence,
            'components' => $leaf->components,
            'path' => $path,
            'next_question' => $parent === null ? null : ['id' => $parent->id, 'question' => $parent->question],
            'siblings' => $siblings,
            'conventions' => $open ? ['rules' => $this->rules($leaf, $siblings), 'notes' => self::NOTES] : null,
            'message' => match ($leaf->status) {
                'gap' => 'No recipe yet. Build to the nearest sibling '.($siblings[0] ?? 'recipe').' and flag it.',
                'byo' => 'No recipe fits. Proceed: build to the nearest recipes'.($siblings === [] ? '' : ' ('.implode(', ', $siblings).')').' and the conventions, and stamp new files `standards-pattern: gap`.',
                default => null,
            },
            'demand' => $open ? [
                'answers' => $path,
                'criteria' => $criteria,
                'unit_type' => explode('.', $leaf->id)[0],
                'ts' => ($this->now ?? new DateTimeImmutable)->format('Y-m-d\TH:i:s\Z'),
            ] : null,
        ];
    }

    /**
     * Up to three ready or draft recipes: those overlapping the criteria
     * first, then the nearest ones in the tree.
     *
     * @param  list<string>  $criteria
     * @return list<string>
     */
    private function siblings(Leaf $leaf, array $criteria): array
    {
        $branch = explode('.', $leaf->id)[0];
        $scores = [];

        foreach ($this->catalog->leaves() as $candidate) {
            if (! in_array($candidate->status, ['ready', 'draft'], true) || $candidate->id === $leaf->id) {
                continue;
            }

            $words = self::words(implode(' ', $candidate->keywords));
            $overlap = count(array_intersect([...$criteria, ...self::words(implode(' ', $leaf->keywords))], $words));

            if ($overlap > 0) {
                $scores[$candidate->id] = $overlap * 10 + (str_starts_with($candidate->id, $branch.'.') ? 5 : 0) + ($candidate->status === 'ready' ? 1 : 0);
            }
        }

        arsort($scores);
        $ids = array_keys($scores);

        foreach ($this->nearest($leaf) as $id) {
            $ids[] = $id;
        }

        return array_slice(array_values(array_unique($ids)), 0, 3);
    }

    /** @return list<string> ready or draft leaves nearest in the tree: siblings first, then up */
    private function nearest(Leaf $leaf): array
    {
        $found = [];
        $from = $leaf->id;

        while (($parent = $this->catalog->parentOf($from)) !== null && count($found) < 3) {
            $this->collect($parent, $leaf->id, $found);
            $from = $parent->id;
        }

        return $found;
    }

    /** @param list<string> $found */
    private function collect(Branch $branch, string $except, array &$found): void
    {
        foreach ($branch->options as $option) {
            $node = $this->catalog->node($option['next']);

            if ($node instanceof Leaf && $node->id !== $except && in_array($node->status, ['ready', 'draft'], true) && ! in_array($node->id, $found, true)) {
                $found[] = $node->id;
            }
        }

        foreach ($branch->options as $option) {
            $node = $this->catalog->node($option['next']);

            if ($node instanceof Branch) {
                $this->collect($node, $except, $found);
            }
        }
    }

    /**
     * @param  list<string>  $siblings
     * @return list<string>
     */
    private function rules(Leaf $leaf, array $siblings): array
    {
        $rules = $leaf->checks;

        foreach ($siblings as $id) {
            $sibling = $this->catalog->node($id);
            $rules = [...$rules, ...($sibling instanceof Leaf ? $sibling->checks : [])];
        }

        $rules = array_values(array_unique([...$rules, 'actions', '20', '21', 'js-imports']));
        sort($rules);

        return $rules;
    }

    /** @return list<string> the answers from the root to a node */
    private function pathTo(string $id): array
    {
        $answers = [];

        while (($parent = $this->catalog->parentOf($id)) !== null) {
            foreach ($parent->options as $option) {
                if ($option['next'] === $id) {
                    array_unshift($answers, $option['answer']);
                }
            }

            $id = $parent->id;
        }

        return $answers;
    }

    /** @return list<string> catalog keyword words: the only words a demand record may contain */
    private function vocabulary(): array
    {
        $words = [];

        foreach ($this->catalog->leaves() as $leaf) {
            $words = [...$words, ...self::words(implode(' ', $leaf->keywords))];
        }

        return array_values(array_unique($words));
    }

    /**
     * @param  list<string>  $texts
     * @param  list<string>  $vocabulary
     * @return list<string>
     */
    private static function criteria(array $texts, array $vocabulary): array
    {
        $criteria = array_values(array_unique(array_intersect(self::words(implode(' ', $texts)), $vocabulary)));
        sort($criteria);

        return $criteria;
    }

    /** @return list<string> */
    private static function words(string $text): array
    {
        return array_values(array_filter(preg_split('/[^a-z0-9]+/', strtolower($text), flags: PREG_SPLIT_NO_EMPTY) ?: [], fn (string $word): bool => strlen($word) > 2));
    }

    /** @return array{contract: int, type: string, error: string} */
    public static function error(string $message): array
    {
        return ['contract' => self::CONTRACT, 'type' => 'error', 'error' => $message];
    }
}
