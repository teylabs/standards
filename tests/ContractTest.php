<?php

declare(strict_types=1);

use Tey\Standards\Patterns\Leaf;
use Tey\Standards\Patterns\Lookup;
use Tey\Standards\Patterns\TreeCatalog;

/**
 * A small JSON-schema subset (type, const, enum, required, properties,
 * items, minItems): enough to pin the stable fields without a dependency.
 *
 * @return list<string> violations
 */
function contractViolations(mixed $value, array $schema, string $at = '$'): array
{
    $errors = [];
    $types = (array) ($schema['type'] ?? []);
    $actual = match (true) {
        is_array($value) && array_is_list($value) && $value !== [] => 'array',
        is_array($value) => in_array('array', $types, true) && $value === [] ? 'array' : 'object',
        is_string($value) => 'string',
        is_int($value) => 'integer',
        is_bool($value) => 'boolean',
        $value === null => 'null',
        default => gettype($value),
    };

    if ($types !== [] && ! in_array($actual, $types, true)) {
        return ["{$at} is {$actual}, expected ".implode('|', $types)];
    }

    if (array_key_exists('const', $schema) && $value !== $schema['const']) {
        $errors[] = "{$at} must be ".json_encode($schema['const']);
    }

    if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
        $errors[] = "{$at} must be one of ".implode(', ', $schema['enum']);
    }

    foreach ($schema['required'] ?? [] as $key) {
        if (! is_array($value) || ! array_key_exists($key, $value)) {
            $errors[] = "{$at}.{$key} is required";
        }
    }

    foreach ($schema['properties'] ?? [] as $key => $property) {
        if (is_array($value) && array_key_exists($key, $value)) {
            $errors = [...$errors, ...contractViolations($value[$key], $property, "{$at}.{$key}")];
        }
    }

    if (isset($schema['minItems']) && is_array($value) && count($value) < $schema['minItems']) {
        $errors[] = "{$at} needs at least {$schema['minItems']} items";
    }

    if (isset($schema['items']) && is_array($value)) {
        foreach ($value as $index => $item) {
            $errors = [...$errors, ...contractViolations($item, $schema['items'], "{$at}[{$index}]")];
        }
    }

    return $errors;
}

function contractSchema(): array
{
    return json_decode(file_get_contents(__DIR__.'/Contract/pattern-contract.schema.json'), true, flags: JSON_THROW_ON_ERROR);
}

test('the stable-field snapshot is contract 1 and matches the documented stable fields', function () {
    $schema = contractSchema();
    $doc = file_get_contents(dirname(__DIR__).'/docs/pattern-contract.md');

    expect($schema['contract'])->toBe(Lookup::CONTRACT)
        ->and(array_keys($schema['types']))->toBe(['plan', 'question', 'leaf', 'error']);

    foreach ($schema['types'] as $type => $shape) {
        foreach ($shape['required'] as $field) {
            expect($doc)->toMatch('/\| `'.preg_quote($field, '/').'` \| [^|]+\| stable \|/');
        }
    }
});

test('every find and recipe response keeps the stable fields of contract 1', function () {
    $lookup = new Lookup(TreeCatalog::shipped());
    $schema = contractSchema()['types'];
    $responses = [
        $lookup->find(['answers' => []]),
        $lookup->find(['answers' => ['4', '2']]),
        $lookup->find(['answers' => ['None of these']]),
        $lookup->find(['path' => 'resources/js/pages/settings/A/Page.vue']),
        $lookup->find(['intent' => 'quantum']),
        $lookup->find(['answers' => ['4', 'nonsense']]),
        $lookup->recipe('nope'),
        ...array_map(fn ($feature): array => $lookup->plan($feature), TreeCatalog::shipped()->features()),
        ...array_map(fn (Leaf $leaf): array => $lookup->recipe($leaf->id), TreeCatalog::shipped()->leaves()),
    ];
    $seen = [];

    foreach ($responses as $response) {
        expect($response['contract'] ?? null)->toBe(1)
            ->and($schema)->toHaveKey($response['type']);
        expect(contractViolations($response, $schema[$response['type']]))->toBe([], json_encode($response));
        $seen[$response['type']] = true;
    }

    expect(array_keys($seen))->toEqualCanonicalizing(['plan', 'question', 'leaf', 'error']);
});

test('the snapshot check fails when a stable field changes', function () {
    $schema = contractSchema()['types'];
    $leaf = (new Lookup(TreeCatalog::shipped()))->recipe('ui-page.index');

    unset($leaf['recipe_make']);
    expect(contractViolations($leaf, $schema['leaf']))->toContain('$.recipe_make is required');
    expect(contractViolations([...$leaf, 'recipe_make' => null, 'status' => 'done'], $schema['leaf']))->toContain('$.status must be one of ready, draft, gap, byo');
});
