# Pattern contract 1

`Tey\Standards\Patterns\Lookup` answers "what should I build with?". Hosted tools call it, for example a recipe MCP whose `find` and `recipe` tools wrap these two methods. `php artisan standards:pattern --json` is the offline CLI over the same library. The library depends only on PHP and `symfony/yaml` (for compiling the catalog), never on Laravel.

```php
use Tey\Standards\Patterns\Lookup;
use Tey\Standards\Patterns\TreeCatalog;

$lookup = new Lookup(TreeCatalog::shipped(), recipes: $bakeryInstalled);

$lookup->find(['intent' => 'manage records with bulk actions']); // plan, or question/leaf
$lookup->find(['path' => 'app/Mcp/Tools/ReadInvoicesTool.php']);  // question with candidates, or leaf
$lookup->find(['answers' => ['1', 'A signed-in user of the app']]); // question or leaf
$lookup->recipe('ui-page.index');                                    // leaf or error
```

The arguments work like this:

- `intent` matches **features** first. A feature is a plan composed of unit leaves. When no feature matches, the intent is matched against unit leaves.
- `path` matches unit leaves by their path globs.
- `answers` walks the unit tree from the root question. Each answer is an option number (from 1), the option's text, or its `next` id.

Every path ends in a leaf. Each question has a "None of these" exit to a build-your-own (`byo`) leaf.

## Versioning

Every response carries `"contract": 1`.

- **Stable** fields keep their name, type and meaning for as long as `contract` is 1. They are never removed or retyped. New fields may appear at any time.
- **Experimental** fields may change or disappear within contract 1. Don't build on them without a fallback.

A breaking change to a stable field ships as `contract: 2`. `tests/Contract/pattern-contract.schema.json` snapshots the stable fields, and `tests/ContractTest.php` fails CI when a response drops one or changes its type.

## `type: "plan"`

Returned by `find` with an `intent` that matches a feature.

| Field | Type | Stability |
| ----- | ---- | --------- |
| `contract` | `1` | stable |
| `type` | `"plan"` | stable |
| `feature` | object | stable |
| `feature.id` | string | stable |
| `feature.summary` | string | experimental |
| `units` | list, never empty | stable |
| `units[].leaf` | string, a unit leaf id | stable |
| `units[].recipe` | string | stable |
| `units[].make` | string or null | stable |
| `units[].role` | string, such as `list` or `write` | stable |
| `units[].when` | string `question=answer` or null; the unit applies only for that answer | experimental |
| `units[].status` | `ready`, `draft`, `gap` or `byo` | experimental |
| `units[].recipe_make`, `units[].preferred_make` | string or null | experimental |
| `units[].siblings` | list of leaf ids; for `gap` and `byo` units, the nearest recipes | experimental |
| `units[].message` | string or null | experimental |
| `questions` | list of `{id, question, options: [string]}` that change the composition | experimental |

## `type: "question"`

Returned by `find` partway through a traversal, for an ambiguous path or intent, and when nothing matches (the root question).

| Field | Type | Stability |
| ----- | ---- | --------- |
| `contract` | `1` | stable |
| `type` | `"question"` | stable |
| `id` | string, the node id | stable |
| `question` | string | stable |
| `options` | list, at least two | stable |
| `options[].answer` | string | stable |
| `options[].next` | string, a node id | stable |
| `why` | string or null | experimental |
| `path` | list of answers taken from the root | experimental |
| `candidates` | list of `{id, status, summary, make}` matched by intent or path | experimental |

## `type: "leaf"`

Returned by `find` at the end of a traversal or for a single match, and by `recipe`.

| Field | Type | Stability |
| ----- | ---- | --------- |
| `contract` | `1` | stable |
| `type` | `"leaf"` | stable |
| `id` | string | stable |
| `recipe` | string, the recipe id or name | stable |
| `status` | `ready`, `draft`, `gap` or `byo` | stable |
| `make` | string or null: `standards:make <unit> --flavor=<flavor>`, offline and deterministic | stable |
| `recipe_make` | string or null: a recipe-package command such as `mod:butter/mcp-parity {Name}` | stable |
| `preferred_make`, `preferred_make_source` | the command to run (`recipe_make` when the recipe package is installed) and its source | experimental |
| `summary`, `kit`, `checks`, `paths`, `keywords` | strings and lists | experimental |
| `components` | list of `{name, kit_component, use, props_hint}`; each `kit_component` is a `@kit/ui` export | experimental |
| `evidence` | `{apps, count, prs, past_apps?, past_count?}`, counts only | experimental |
| `path` | list of answers from the root | experimental |
| `next_question` | `{id, question}` of the parent, or null | experimental |
| `siblings` | list of leaf ids, nearest recipes for `gap` and `byo` | experimental |
| `conventions` | `{rules, notes}` for `gap` and `byo`, otherwise null | experimental |
| `message` | for `gap`: "No recipe yet. Build to the nearest sibling `<id>` and flag it."; for `byo`: a note to proceed; otherwise null | experimental |
| `demand` | for `gap` and `byo`: `{answers, criteria, unit_type, ts}`, otherwise null | experimental |

`demand` is anonymised. It holds only the tree's own answer texts, words from the catalog's keyword vocabulary, the top-level branch and a UTC timestamp. It never holds code, names or paths. The CLI appends it to `storage/standards/gap-demand.jsonl`. Hosts may store it the same way, and `standards:check --json` counts it under `gaps.demand`.

## `type: "error"`

| Field | Type | Stability |
| ----- | ---- | --------- |
| `contract` | `1` | stable |
| `type` | `"error"` | stable |
| `error` | string, human-readable | stable |
