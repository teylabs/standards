<?php

declare(strict_types=1);

/**
 * The JSON-schema keywords docs/schemas/mcp-capabilities.schema.json uses, enough to pin what it accepts without a dependency.
 *
 * @param  array<string, mixed>  $schema
 * @param  array<string, mixed>  $root
 */
function mcpSchemaValid(mixed $value, array $schema, array $root): bool
{
    if (isset($schema['$ref'])) {
        return mcpSchemaValid($value, $root['$defs'][substr($schema['$ref'], strlen('#/$defs/'))], $root);
    }
    $type = match (true) {
        $value instanceof stdClass => 'object',
        is_array($value) => 'array',
        is_string($value) => 'string',
        is_int($value) => 'integer',
        default => gettype($value),
    };
    $checks = [
        ! isset($schema['type']) || $schema['type'] === $type,
        ! array_key_exists('const', $schema) || $value === $schema['const'],
        ! isset($schema['enum']) || in_array($value, $schema['enum'], true),
        ! isset($schema['minLength']) || ($type === 'string' && mb_strlen($value) >= $schema['minLength']),
        ! isset($schema['pattern']) || ($type === 'string' && preg_match('~'.$schema['pattern'].'~u', $value) === 1),
        ! isset($schema['minItems']) || ($type === 'array' && count($value) >= $schema['minItems']),
        ! ($schema['uniqueItems'] ?? false) || ($type === 'array' && count(array_unique(array_map(json_encode(...), $value))) === count($value)),
        ! isset($schema['minProperties']) || ($type === 'object' && count(get_object_vars($value)) >= $schema['minProperties']),
        ! isset($schema['required']) || ($type === 'object' && array_diff($schema['required'], array_keys(get_object_vars($value))) === []),
        ! isset($schema['not']) || ! mcpSchemaValid($value, $schema['not'], $root),
        ! isset($schema['oneOf']) || count(array_filter($schema['oneOf'], fn (array $branch): bool => mcpSchemaValid($value, $branch, $root))) === 1,
        ! isset($schema['allOf']) || array_all($schema['allOf'], fn (array $branch): bool => mcpSchemaValid($value, $branch, $root)),
        ! isset($schema['if']) || ! mcpSchemaValid($value, $schema['if'], $root) || mcpSchemaValid($value, $schema['then'] ?? [], $root),
        ! isset($schema['items']) || $type !== 'array' || array_all($value, fn (mixed $item): bool => mcpSchemaValid($item, $schema['items'], $root)),
    ];
    if ($type === 'object') {
        foreach (get_object_vars($value) as $key => $item) {
            $checks[] = isset($schema['properties'][$key])
                ? mcpSchemaValid($item, $schema['properties'][$key], $root)
                : ! isset($schema['additionalProperties']) || mcpSchemaValid($item, $schema['additionalProperties'], $root);
        }
    }

    return ! in_array(false, $checks, true);
}

function mcpManifestValid(array $capabilities): bool
{
    $schema = json_decode((string) file_get_contents(dirname(__DIR__).'/docs/schemas/mcp-capabilities.schema.json'), true, flags: JSON_THROW_ON_ERROR);
    $manifest = json_decode((string) json_encode(['version' => 1, 'inventory' => [['path' => 'notes', 'capabilities' => ['notes.write'], 'variants' => ['default' => ['notes.write']]]], 'capabilities' => $capabilities]));

    return mcpSchemaValid($manifest, $schema, $schema);
}

const MCP_FULL_CAPABILITY = [
    'id' => 'notes.write', 'kind' => 'write',
    'ui' => [['path' => 'resources/js/pages/Notes/Page.vue', 'symbol' => 'save']],
    'domain' => ['path' => 'app/Actions/SaveNote.php', 'symbol' => 'SaveNote'],
    'mcp' => ['status' => 'implemented', 'kind' => 'tool', 'name' => 'notes.save', 'entrypoint' => ['path' => 'app/Mcp/Tools/SaveNote.php', 'symbol' => 'SaveNote'], 'scopes' => ['notes:write']],
    'tests' => [['path' => 'tests/Feature/NotesTest.php', 'name' => 'saves', 'covers' => ['success']]],
    'variants' => [['id' => 'default', 'ui' => ['options' => ['mode' => ['draft']], 'defaults' => ['mode' => 'draft'], 'effects' => ['saved']], 'mcp' => ['options' => ['mode' => ['draft']], 'defaults' => ['mode' => 'draft'], 'effects' => ['saved'], 'status' => 'implemented'], 'tests' => [['path' => 'tests/Feature/NotesTest.php', 'name' => 'saves', 'covers' => ['success']]]]],
    'routes' => ['notes.store'],
];

test('the shipped capability schema accepts a minimal uiOnly entry, as rule 55 does', function () {
    expect(mcpManifestValid([MCP_FULL_CAPABILITY, ['id' => 'generated.settings.members', 'uiOnly' => 'generated: confirm or map to an MCP tool', 'routes' => ['POST /settings/members']]]))->toBeTrue();
});

test('the shipped capability schema still rejects an incomplete capability and a uiOnly entry without its reason or routes', function (array $capability) {
    expect(mcpManifestValid([MCP_FULL_CAPABILITY]))->toBeTrue('the full control passes')
        ->and(mcpManifestValid([MCP_FULL_CAPABILITY, $capability]))->toBeFalse();
})->with([
    'full capability without ui' => [array_diff_key(MCP_FULL_CAPABILITY, ['ui' => true])],
    'full capability without variants' => [array_diff_key(MCP_FULL_CAPABILITY, ['variants' => true])],
    'uiOnly with an empty reason' => [['id' => 'members.invite', 'uiOnly' => '', 'routes' => ['members.invite']]],
    'uiOnly with a blank reason' => [['id' => 'members.invite', 'uiOnly' => '   ', 'routes' => ['members.invite']]],
    'uiOnly reason that is not a string' => [['id' => 'members.invite', 'uiOnly' => true, 'routes' => ['members.invite']]],
    'uiOnly without routes' => [['id' => 'members.invite', 'uiOnly' => 'Admin-only screen.']],
    'uiOnly with no routes' => [['id' => 'members.invite', 'uiOnly' => 'Admin-only screen.', 'routes' => []]],
    'uiOnly without an id' => [['uiOnly' => 'Admin-only screen.', 'routes' => ['members.invite']]],
]);
