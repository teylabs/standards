<?php

declare(strict_types=1);

namespace Tey\Standards\Patterns;

use Composer\InstalledVersions;
use InvalidArgumentException;

/**
 * The traversal both standards:pattern and the pattern_lookup MCP tool use:
 * step mode walks one question at a time; intent mode matches words or a path
 * to candidate leaves plus the questions that tell them apart.
 */
final class Lookup
{
    /** The package that runs recipe_make commands; when installed its recipe is preferred. */
    public const RECIPE_PACKAGE = 'butter/bakery';

    public function __construct(private readonly PatternCatalog $catalog, private readonly bool $recipes = false) {}

    /** Whether the recipe package is installed in the running app. */
    public static function recipesInstalled(): bool
    {
        return class_exists(InstalledVersions::class) && InstalledVersions::isInstalled(self::RECIPE_PACKAGE);
    }

    /** @return array<string, mixed> */
    public function step(?string $node = null, ?string $answer = null): array
    {
        $current = $this->catalog->node($node ?? $this->catalog->root()->id);

        if ($current === null) {
            throw new InvalidArgumentException("Unknown pattern node [{$node}].");
        }

        if ($answer !== null && $current instanceof Branch) {
            $next = null;

            foreach ($current->options as $index => $option) {
                if ($answer === (string) ($index + 1) || strcasecmp(trim($answer), $option['answer']) === 0 || $answer === $option['next']) {
                    $next = $option['next'];
                }
            }

            if ($next === null) {
                throw new InvalidArgumentException("[{$answer}] is not an answer to {$current->id}; answer with an option number, its text or its next id.");
            }

            $current = $this->catalog->node($next);
        }

        return $current instanceof Leaf ? $this->leaf($current) : $current->toArray();
    }

    /** @return array<string, mixed> */
    public function intent(string $query): array
    {
        $exact = $this->catalog->node($query);

        if ($exact !== null) {
            return $exact instanceof Leaf ? $this->leaf($exact) : $exact->toArray();
        }

        $byPath = str_contains($query, '/') || preg_match('/\.(php|vue|ts|js)$/', $query) === 1;
        $candidates = array_slice($byPath ? $this->catalog->forPath($query) : $this->catalog->search($query), 0, 5);
        $questions = [];

        foreach ($candidates as $leaf) {
            $parent = $this->catalog->parentOf($leaf->id);

            if ($parent !== null && count($candidates) > 1 && ! isset($questions[$parent->id]) && count($questions) < 2) {
                $questions[$parent->id] = $parent->toArray();
            }
        }

        if ($candidates === []) {
            $questions = [$this->catalog->root()->id => $this->catalog->root()->toArray()];
        }

        return [
            'type' => 'candidates',
            'query' => $query,
            'mode' => $byPath ? 'path' : 'intent',
            'candidates' => array_map($this->leaf(...), $candidates),
            'questions' => array_values($questions),
        ];
    }

    /** @return list<array<string, mixed>> gap leaves, most evidenced (apps × count) first */
    public function gaps(): array
    {
        $gaps = array_values(array_filter($this->catalog->leaves(), fn (Leaf $leaf): bool => $leaf->status === 'gap'));
        usort($gaps, fn (Leaf $a, Leaf $b): int => [$b->weight(), $a->id] <=> [$a->weight(), $b->id]);

        return array_map(fn (Leaf $leaf): array => [...$this->leaf($leaf), 'weight' => $leaf->weight()], $gaps);
    }

    /** @return array<string, mixed> */
    public function leaf(Leaf $leaf): array
    {
        $payload = $leaf->toArray();
        $preferRecipe = $this->recipes && $leaf->recipeMake !== null;
        $payload['preferred_make'] = $preferRecipe ? str_replace('{Name}', '<Name>', $leaf->recipeMake) : ($leaf->make === null ? null : $leaf->make.' <Name>');
        $payload['preferred_make_source'] = $preferRecipe ? 'bakery ('.self::RECIPE_PACKAGE.')' : ($leaf->make === null ? null : 'tey/standards');
        $parent = $this->catalog->parentOf($leaf->id);

        if ($parent !== null) {
            $payload['next_question'] = $parent->toArray();
        }

        if ($leaf->status === 'gap') {
            $payload['message'] = 'No recipe yet. Build to the nearest sibling '.($this->sibling($leaf) ?? $this->catalog->root()->id).' and flag it.';
        }

        return $payload;
    }

    /** The nearest non-gap leaf: a sibling first, then cousins up the tree. */
    public function sibling(Leaf $leaf): ?string
    {
        $from = $leaf->id;

        while (($parent = $this->catalog->parentOf($from)) !== null) {
            $found = $this->firstReady($parent, $leaf->id);

            if ($found !== null) {
                return $found;
            }

            $from = $parent->id;
        }

        return null;
    }

    private function firstReady(Branch $branch, string $except): ?string
    {
        foreach (['ready', 'draft'] as $status) {
            foreach ($branch->options as $option) {
                $node = $this->catalog->node($option['next']);

                if ($node instanceof Leaf && $node->id !== $except && $node->status === $status) {
                    return $node->id;
                }
            }
        }

        foreach ($branch->options as $option) {
            $node = $this->catalog->node($option['next']);

            if ($node instanceof Branch && $node->id !== $except && ($found = $this->firstReady($node, $except)) !== null) {
                return $found;
            }
        }

        return null;
    }
}
