<?php

declare(strict_types=1);

namespace Tey\Standards\Enforcement;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Tey\Standards\Patterns\Lookup;
use Tey\Standards\Patterns\TreeCatalog;

/** The standards:pattern lookup as an MCP tool, registered with Boost when laravel/mcp is installed. */
final class PatternLookupTool extends Tool
{
    protected string $name = 'pattern_lookup';

    protected string $description = 'Find the pattern, kit components and make command before building. Without arguments it asks the root question; answer with node + answer to step. Pass query (an intent or a project-relative path) for candidate leaves plus disambiguating questions, or gaps=true for leaves with no recipe yet.';

    public function handle(Request $request): Response
    {
        $lookup = new Lookup(TreeCatalog::shipped(), Lookup::recipesInstalled());

        try {
            $query = $request->get('query');
            $result = match (true) {
                (bool) $request->get('gaps', false) => ['type' => 'gaps', 'leaves' => $lookup->gaps()],
                is_string($query) && $query !== '' => $lookup->intent($query),
                default => $lookup->step($request->get('node'), $request->get('answer')),
            };
        } catch (InvalidArgumentException $error) {
            return Response::error($error->getMessage());
        }

        return Response::text(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('An intent ("a settings page with toggles") or a project-relative path.'),
            'node' => $schema->string()->description('Step mode: the node id being answered.'),
            'answer' => $schema->string()->description('Step mode: an option number, its answer text or its next id.'),
            'gaps' => $schema->boolean()->description('List gap leaves.'),
        ];
    }
}
