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

    /** @var list<string> component names the kit's @kit/ui barrel exports */
    private static array $components = [];

    /** The root's build-your-own exit. */
    public const BYO = 'byo';

    public const BRANCHES = ['ui-page', 'data-actions', 'mcp', 'settings', 'mail-notifications', 'background', 'access-tenancy', 'integrations'];

    public const UNITS = [
        'page' => ['index', 'show', 'form'],
        'action' => ['plain'],
        'capability-tool' => ['read', 'write'],
        'settings-section' => ['form', 'toggles'],
        'crud' => ['resource'],
        // A Workbench page (pattern ui-page.workbench): tey/kit's multi-pane editor over a sequence of items.
        'workbench' => ['page'],
        // Operator commands (pattern background.command): stubs on Tey\Standards\Operator, not tey/mod's make:command.
        'command' => ['backfill', 'maintenance', 'report'],
        // Integrations (patterns integrations.webhook and integrations.api-client).
        'webhook' => ['signed'],
        'api-client' => ['sdk', 'http'],
        // Delegated to tey/mod's make:* generators; standards stamps the result.
        'model' => ['plain', 'domain'],
        'policy' => ['plain'],
        'job' => ['plain'],
        'notification' => ['plain'],
    ];

    /** @return array{schema: int, root: string, nodes: array<string, array<string, mixed>>} */
    public static function compile(string $directory): array
    {
        $exports = json_decode((string) @file_get_contents($directory.'/kit-exports.json'), true);

        if (! is_array($exports) || ! is_array($exports['components'] ?? null)) {
            throw new InvalidArgumentException('Missing or invalid kit-exports.json.');
        }

        self::$components = $exports['components'];
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

                if (isset($nodes[$node['id']]) || in_array($node['id'], [self::ROOT, self::BYO], true)) {
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

        $options[] = ['answer' => 'None of these', 'next' => self::BYO];
        $nodes = [
            self::ROOT => ['id' => self::ROOT, 'question' => self::QUESTION, 'options' => $options],
            ...$nodes,
            self::BYO => ['id' => self::BYO, 'recipe' => 'Build your own', 'summary' => 'Nothing in the tree fits. Proceed with the general conventions, stamp new files `standards-pattern: gap`, and the demand is recorded.', 'kit' => [], 'make' => null, 'checks' => [], 'paths' => [], 'keywords' => [], 'status' => 'byo'],
        ];
        self::validate($nodes);

        return ['schema' => 1, 'root' => self::ROOT, 'features' => self::features($directory.'/features', $nodes), 'nodes' => $nodes];
    }

    /**
     * Features sit above the unit tree: each composes unit leaves into a plan.
     *
     * @param  array<string, array<string, mixed>>  $nodes
     * @return array<string, array<string, mixed>>
     */
    private static function features(string $directory, array $nodes): array
    {
        $features = [];

        foreach (glob($directory.'/*.yaml') ?: [] as $file) {
            try {
                $feature = Yaml::parseFile($file);
            } catch (ParseException $error) {
                throw new InvalidArgumentException('features/'.basename($file).' is not valid YAML: '.$error->getMessage(), previous: $error);
            }

            $id = $feature['id'] ?? null;

            if (! is_array($feature) || $id !== basename($file, '.yaml') || ! is_string($feature['summary'] ?? null) || ! is_array($feature['keywords'] ?? null) || ! is_array($feature['composition'] ?? null) || $feature['composition'] === []) {
                throw new InvalidArgumentException('features/'.basename($file).' needs id (its file name), summary, keywords and a non-empty composition.');
            }

            $extra = array_diff(array_keys($feature), ['id', 'summary', 'keywords', 'questions', 'composition']);

            if ($extra !== []) {
                throw new InvalidArgumentException("Feature {$id} has unknown fields: ".implode(', ', $extra).'.');
            }

            $questions = [];

            foreach ($feature['questions'] ?? [] as $question) {
                if (! is_array($question) || ! is_string($question['id'] ?? null) || ! is_string($question['question'] ?? null) || ! is_array($question['options'] ?? null) || count($question['options']) < 2 || array_filter($question['options'], 'is_string') !== $question['options']) {
                    throw new InvalidArgumentException("Feature {$id} questions need id, question and at least two string options.");
                }

                $questions[$question['id']] = $question;
            }

            foreach ($feature['composition'] as $unit) {
                if (! is_array($unit) || ! is_string($unit['unit_leaf_id'] ?? null) || ! is_string($unit['role'] ?? null) || array_diff(array_keys($unit), ['unit_leaf_id', 'role', 'when']) !== []) {
                    throw new InvalidArgumentException("Feature {$id} composition entries need unit_leaf_id and role, and optionally when.");
                }

                if (! isset($nodes[$unit['unit_leaf_id']]) || isset($nodes[$unit['unit_leaf_id']]['question'])) {
                    throw new InvalidArgumentException("Feature {$id} names [{$unit['unit_leaf_id']}], which is not a unit leaf.");
                }

                if (isset($unit['when'])) {
                    [$question, $answer] = array_pad(explode('=', (string) $unit['when'], 2), 2, null);

                    if (! isset($questions[$question]) || ! in_array($answer, $questions[$question]['options'], true)) {
                        throw new InvalidArgumentException("Feature {$id} when [{$unit['when']}] must name one of its questions and an option.");
                    }
                }
            }

            $features[$id] = ['id' => $id, 'summary' => $feature['summary'], 'keywords' => $feature['keywords'], 'questions' => array_values($questions), 'composition' => $feature['composition']];
        }

        ksort($features);

        return $features;
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
            throw new InvalidArgumentException("Leaf {$id} status must be ready, draft, gap or byo.");
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

        foreach ($node['kit'] as $entry) {
            if (str_starts_with($entry, '@kit/ui ') && ! in_array(substr($entry, 8), self::$components, true)) {
                throw new InvalidArgumentException("Leaf {$id} names [{$entry}], which the kit does not export.");
            }
        }

        $components = $node['components'] ?? [];

        if (! is_array($components) || ! array_is_list($components)) {
            throw new InvalidArgumentException("Leaf {$id} components must be a list.");
        }

        foreach ($components as $component) {
            if (! is_array($component) || array_keys($component) !== ['name', 'kit_component', 'use', 'props_hint'] || array_filter($component, 'is_string') !== $component) {
                throw new InvalidArgumentException("Leaf {$id} components need exactly name, kit_component, use and props_hint strings.");
            }

            if (! in_array($component['kit_component'], self::$components, true)) {
                throw new InvalidArgumentException("Leaf {$id} component [{$component['kit_component']}] is not exported by the kit.");
            }
        }

        $evidence = $node['evidence'] ?? null;

        if ($evidence !== null) {
            $valid = is_array($evidence) && in_array(array_keys($evidence), [['apps', 'count', 'prs'], ['apps', 'count', 'prs', 'past_apps', 'past_count']], true);

            foreach ($valid ? $evidence : [] as $value) {
                $valid = $valid && is_int($value) && $value >= 0;
            }

            // Public evidence is counts only: no app names or change references ship in the package.
            if (! $valid) {
                throw new InvalidArgumentException("Leaf {$id} evidence must be {apps, count, prs} with optional {past_apps, past_count}, all non-negative counts.");
            }
        }

        // A leaf without a generator yet (draft) carries its conventions as rules, the leaves it composes or sits beside, and when it graduates.
        foreach (['rules', 'related'] as $field) {
            $list = $node[$field] ?? [];

            if (! is_array($list) || ! array_is_list($list) || array_filter($list, fn (mixed $item): bool => is_string($item) && trim($item) !== '') !== $list) {
                throw new InvalidArgumentException("Leaf {$id} {$field} must be a list of non-empty strings.");
            }
        }

        $graduate = $node['graduate'] ?? null;

        if ($graduate !== null && (! is_string($graduate) || trim($graduate) === '')) {
            throw new InvalidArgumentException("Leaf {$id} graduate must be a non-empty string.");
        }

        $extra = array_diff(array_keys($node), ['id', 'recipe', 'summary', 'kit', 'make', 'recipe_make', 'checks', 'paths', 'keywords', 'status', 'evidence', 'components', 'rules', 'related', 'graduate']);

        if ($extra !== []) {
            throw new InvalidArgumentException("Leaf {$id} has unknown fields: ".implode(', ', $extra).'.');
        }

        return ['id' => $id, 'recipe' => $node['recipe'], 'summary' => $node['summary'], 'kit' => $node['kit'], 'make' => $make, 'checks' => $node['checks'], 'paths' => $node['paths'], 'keywords' => $node['keywords'], 'status' => $node['status'], ...($recipeMake === null ? [] : ['recipe_make' => $recipeMake]), ...($evidence === null ? [] : ['evidence' => $evidence]), ...($components === [] ? [] : ['components' => $components]), ...(($node['rules'] ?? []) === [] ? [] : ['rules' => $node['rules']]), ...(($node['related'] ?? []) === [] ? [] : ['related' => $node['related']]), ...($graduate === null ? [] : ['graduate' => $graduate])];
    }

    /** @param array<string, array<string, mixed>> $nodes */
    private static function validate(array $nodes): void
    {
        foreach ($nodes as $id => $node) {
            foreach ($node['related'] ?? [] as $related) {
                if (! isset($nodes[$related]) || isset($nodes[$related]['options']) || $related === $id) {
                    throw new InvalidArgumentException("Leaf {$id} relates to {$related}, which is not another leaf.");
                }
            }

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

        // Every path ends in a leaf: each question must reach a build-your-own exit.
        $byo = function (string $id) use (&$byo, $nodes): bool {
            if (($nodes[$id]['status'] ?? null) === 'byo') {
                return true;
            }

            foreach ($nodes[$id]['options'] ?? [] as $option) {
                if ($byo($option['next'])) {
                    return true;
                }
            }

            return false;
        };

        foreach ($nodes as $id => $node) {
            if (isset($node['question']) && ! $byo($id)) {
                throw new InvalidArgumentException("Branch {$id} has no reachable build-your-own (byo) leaf; add a \"None of these\" option.");
            }
        }

        $orphans = array_diff(array_keys($nodes), array_keys($state));

        if ($orphans !== []) {
            throw new InvalidArgumentException('Orphaned pattern nodes: '.implode(', ', $orphans).'.');
        }
    }
}
