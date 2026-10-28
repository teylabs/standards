<?php

declare(strict_types=1);

namespace Tey\Standards\Patterns;

use InvalidArgumentException;

final class TreeCatalog implements PatternCatalog
{
    /** @var array<string, Branch|Leaf> */
    private array $nodes = [];

    /** @var list<Feature> */
    private array $features = [];

    /** @var array<string, string> child id => parent id */
    private array $parents = [];

    /** @param array{schema: int, root: string, features?: array<string, array<string, mixed>>, nodes: array<string, array<string, mixed>>} $catalog */
    public function __construct(array $catalog)
    {
        foreach ($catalog['features'] ?? [] as $feature) {
            $this->features[] = new Feature($feature['id'], $feature['summary'], $feature['keywords'], $feature['questions'], $feature['composition']);
        }

        foreach ($catalog['nodes'] as $id => $node) {
            $this->nodes[$id] = isset($node['question'])
                ? new Branch($id, $node['question'], $node['options'], $node['why'] ?? null)
                : new Leaf($id, $node['recipe'], $node['summary'], $node['kit'], $node['make'], $node['checks'], $node['paths'], $node['keywords'], $node['status'], $node['recipe_make'] ?? null, $node['evidence'] ?? null, $node['components'] ?? [], $node['rules'] ?? [], $node['related'] ?? [], $node['graduate'] ?? null);

            foreach ($node['options'] ?? [] as $option) {
                $this->parents[$option['next']] = $id;
            }
        }
    }

    public static function shipped(): self
    {
        $contents = file_get_contents(dirname(__DIR__, 2).'/resources/patterns/catalog.json');

        if ($contents === false) {
            throw new InvalidArgumentException('Cannot read the shipped pattern catalog.');
        }

        return new self(json_decode($contents, true, flags: JSON_THROW_ON_ERROR));
    }

    public function root(): Branch
    {
        $root = $this->nodes[Compiler::ROOT];
        assert($root instanceof Branch);

        return $root;
    }

    public function features(): array
    {
        return $this->features;
    }

    public function node(string $id): Branch|Leaf|null
    {
        return $this->nodes[$id] ?? null;
    }

    public function parentOf(string $id): ?Branch
    {
        $parent = isset($this->parents[$id]) ? $this->nodes[$this->parents[$id]] : null;

        return $parent instanceof Branch ? $parent : null;
    }

    public function leaves(): array
    {
        return array_values(array_filter($this->nodes, fn (Branch|Leaf $node): bool => $node instanceof Leaf));
    }

    public function forPath(string $path): array
    {
        $path = ltrim(str_replace('\\', '/', $path), './');

        return array_values(array_filter($this->leaves(), function (Leaf $leaf) use ($path): bool {
            foreach ($leaf->paths as $glob) {
                if (self::matches($glob, $path)) {
                    return true;
                }
            }

            return false;
        }));
    }

    public function search(string $intent): array
    {
        $words = preg_split('/[^a-z0-9]+/', strtolower($intent), flags: PREG_SPLIT_NO_EMPTY) ?: [];
        $scores = [];

        foreach ($this->leaves() as $leaf) {
            $haystack = strtolower(implode(' ', [$leaf->id, $leaf->recipe, ...$leaf->keywords]));
            $score = 0;

            foreach ($leaf->keywords as $keyword) {
                $score += str_contains(strtolower($intent), strtolower($keyword)) ? 3 : 0;
            }

            foreach ($words as $word) {
                $score += strlen($word) > 2 && str_contains($haystack, $word) ? 1 : 0;
            }

            if ($score > 0) {
                $scores[$leaf->id] = $score;
            }
        }

        arsort($scores);

        return array_map(fn (string $id): Leaf => $this->nodes[$id], array_keys($scores));
    }

    public function forRule(string $rule): ?Leaf
    {
        foreach ($this->leaves() as $leaf) {
            if ($leaf->status === 'ready' && in_array($rule, $leaf->checks, true)) {
                return $leaf;
            }
        }

        return null;
    }

    /** `**` crosses directories, `*` stays within one segment. */
    public static function matches(string $glob, string $path): bool
    {
        $pattern = preg_quote($glob, '#');
        $pattern = str_replace(['/\*\*/', '\*\*', '\*'], ['/(?:.*/)?', '.*', '[^/]*'], $pattern);

        return preg_match('#^'.$pattern.'$#D', $path) === 1;
    }
}
