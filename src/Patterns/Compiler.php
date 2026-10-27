<?php

declare(strict_types=1);

namespace Tey\Standards\Patterns;

use InvalidArgumentException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Tey\Standards\Conformance;

/**
 * Compiles one YAML file per top-level branch into catalog.json and rejects
 * an invalid tree: unresolved or orphaned nodes, cycles, malformed nodes, and
 * ready leaves without a generator.
 */
final class Compiler
{
    public const ROOT = 'root';

    public const QUESTION = 'What are you building?';

    public const BRANCHES = ['ui-page', 'data-actions', 'mcp', 'settings', 'mail-notifications', 'background', 'access-tenancy', 'integrations'];

    public const UNITS = ['page' => ['index', 'show', 'form'], 'action' => ['plain'], 'capability-tool' => ['read', 'write'], 'settings-section' => ['form', 'toggles']];

    /** @return array{schema: int, root: string, nodes: array<string, array<string, mixed>>} */
    public static function compile(string $directory): array
    {
        $nodes = [];
        $options = [];

        foreach (self::BRANCHES as $branch) {
            $file = $directory.'/'.$branch.'.yaml';

            if (! is_file($file)) {
                throw new InvalidArgumentException("Missing pattern branch file {$branch}.yaml.");
            }

            try {
                $source = Yaml::parseFile($file);
            } catch (ParseException $error) {
                throw new InvalidArgumentException("{$branch}.yaml is not valid YAML: ".$error->getMessage(), previous: $error);
            }

            if (! is_array($source) || ! is_string($source['answer'] ?? null) || ($source['entry'] ?? null) !== $branch || ! is_array($source['nodes'] ?? null)) {
                throw new InvalidArgumentException("{$branch}.yaml needs answer, entry: {$branch} and nodes.");
            }

            $options[] = ['answer' => $source['answer'], 'next' => $branch];

            foreach ($source['nodes'] as $node) {
                $node = self::node($node, $branch);

                if (isset($nodes[$node['id']]) || $node['id'] === self::ROOT) {
                    throw new InvalidArgumentException("Duplicate pattern node {$node['id']}.");
                }

                $nodes[$node['id']] = $node;
            }
        }

        foreach (glob($directory.'/*.yaml') ?: [] as $file) {
            if (! in_array(basename($file, '.yaml'), self::BRANCHES, true)) {
                throw new InvalidArgumentException('Unknown pattern branch file '.basename($file).'.');
            }
        }

        $nodes = [self::ROOT => ['id' => self::ROOT, 'question' => self::QUESTION, 'options' => $options], ...$nodes];
        self::validate($nodes);

        return ['schema' => 1, 'root' => self::ROOT, 'nodes' => $nodes];
    }

    /** @return array<string, mixed> */
    private static function node(mixed $node, string $branch): array
    {
        if (! is_array($node) || ! is_string($node['id'] ?? null) || preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)*$/D', $node['id']) !== 1) {
            throw new InvalidArgumentException("{$branch}.yaml has a node without a valid id.");
        }

        $id = $node['id'];

        if ($id !== $branch && ! str_starts_with($id, $branch.'.')) {
            throw new InvalidArgumentException("Node {$id} must be named under {$branch}.");
        }

        if (isset($node['question'])) {
            $extra = array_diff(array_keys($node), ['id', 'question', 'why', 'options']);
            $count = is_array($node['options'] ?? null) ? count($node['options']) : 0;

            if ($extra !== [] || ! is_string($node['question']) || $count < 2 || $count > 5) {
                throw new InvalidArgumentException("Branch {$id} needs a question and 2–5 options, and only id, question, why and options.");
            }

            foreach ($node['options'] as $option) {
                if (! is_array($option) || array_keys($option) !== ['answer', 'next'] || ! is_string($option['answer']) || ! is_string($option['next'])) {
                    throw new InvalidArgumentException("Branch {$id} options need exactly answer and next.");
                }
            }

            return $node;
        }

        foreach (['recipe', 'summary', 'status'] as $field) {
            if (! is_string($node[$field] ?? null) || trim($node[$field]) === '') {
                throw new InvalidArgumentException("Leaf {$id} needs a non-empty {$field}.");
            }
        }

        foreach (['kit', 'checks', 'paths', 'keywords'] as $field) {
            if (! is_array($node[$field] ?? null) || ! array_is_list($node[$field]) || array_filter($node[$field], 'is_string') !== $node[$field]) {
                throw new InvalidArgumentException("Leaf {$id} needs a {$field} list of strings.");
            }
        }

        if (! in_array($node['status'], Leaf::STATUSES, true)) {
            throw new InvalidArgumentException("Leaf {$id} status must be ready, draft or gap.");
        }

        foreach ($node['checks'] as $rule) {
            if (! array_key_exists($rule, Conformance::TESTS)) {
                throw new InvalidArgumentException("Leaf {$id} names an unknown check [{$rule}].");
            }
        }

        $make = $node['make'] ?? null;

        if ($make === null && $node['status'] === 'ready') {
            throw new InvalidArgumentException("Ready leaf {$id} needs a make command.");
        }

        if ($make !== null) {
            if (! is_string($make) || preg_match('/^standards:make ([a-z-]+) --flavor=([a-z-]+)$/D', $make, $match) !== 1 || ! in_array($match[2], self::UNITS[$match[1]] ?? [], true)) {
                throw new InvalidArgumentException("Leaf {$id} make must be standards:make <unit> --flavor=<flavor> with a known unit and flavor.");
            }
        }

        $recipeMake = $node['recipe_make'] ?? null;

        if ($recipeMake !== null && (! is_string($recipeMake) || preg_match('/^mod:[a-z0-9-]+\/[a-z0-9-]+ \{Name\}$/D', $recipeMake) !== 1)) {
            throw new InvalidArgumentException("Leaf {$id} recipe_make must look like mod:<vendor>/<recipe> {Name}.");
        }

        $evidence = $node['evidence'] ?? null;

        if ($evidence !== null) {
            $valid = is_array($evidence) && array_keys($evidence) === ['apps', 'count', 'prs'];

            foreach ($valid ? $evidence : [] as $value) {
                $valid = $valid && is_int($value) && $value >= 0;
            }

            // Public evidence is counts only: no app names or change references ship in the package.
            if (! $valid) {
                throw new InvalidArgumentException("Leaf {$id} evidence must be {apps, count, prs} as non-negative counts.");
            }
        }

        $extra = array_diff(array_keys($node), ['id', 'recipe', 'summary', 'kit', 'make', 'recipe_make', 'checks', 'paths', 'keywords', 'status', 'evidence']);

        if ($extra !== []) {
            throw new InvalidArgumentException("Leaf {$id} has unknown fields: ".implode(', ', $extra).'.');
        }

        return ['id' => $id, 'recipe' => $node['recipe'], 'summary' => $node['summary'], 'kit' => $node['kit'], 'make' => $make, 'checks' => $node['checks'], 'paths' => $node['paths'], 'keywords' => $node['keywords'], 'status' => $node['status'], ...($recipeMake === null ? [] : ['recipe_make' => $recipeMake]), ...($evidence === null ? [] : ['evidence' => $evidence])];
    }

    /** @param array<string, array<string, mixed>> $nodes */
    private static function validate(array $nodes): void
    {
        foreach ($nodes as $id => $node) {
            foreach ($node['options'] ?? [] as $option) {
                if (! isset($nodes[$option['next']])) {
                    throw new InvalidArgumentException("Branch {$id} points to missing node {$option['next']}.");
                }
            }
        }

        $state = [];
        $visit = function (string $id) use (&$visit, &$state, $nodes): void {
            if (($state[$id] ?? null) === 'open') {
                throw new InvalidArgumentException("Pattern tree has a cycle through {$id}.");
            }

            if (isset($state[$id])) {
                throw new InvalidArgumentException("Pattern node {$id} has more than one parent.");
            }

            $state[$id] = 'open';

            foreach ($nodes[$id]['options'] ?? [] as $option) {
                $visit($option['next']);
            }

            $state[$id] = 'done';
        };
        $visit(self::ROOT);

        $orphans = array_diff(array_keys($nodes), array_keys($state));

        if ($orphans !== []) {
            throw new InvalidArgumentException('Orphaned pattern nodes: '.implode(', ', $orphans).'.');
        }
    }
}
