<?php

declare(strict_types=1);

namespace Tey\Standards\Patterns;

final readonly class Leaf
{
    public const STATUSES = ['ready', 'draft', 'gap', 'byo'];

    /**
     * @param  list<string>  $kit
     * @param  list<string>  $checks
     * @param  list<string>  $paths
     * @param  list<string>  $keywords
     */
    public function __construct(
        public string $id,
        public string $recipe,
        public string $summary,
        public array $kit,
        public ?string $make,
        public array $checks,
        public array $paths,
        public array $keywords,
        public string $status,
        public ?string $recipeMake = null,
        /** @var array{apps: int, count: int, prs: int, past_apps?: int, past_count?: int}|null counts only */
        public ?array $evidence = null,
        /** @var list<array{name: string, kit_component: string, use: string, props_hint: string}> */
        public array $components = [],
        /** @var list<string> the conventions a draft leaf is built to, until it has a generator */
        public array $rules = [],
        /** @var list<string> leaf ids it composes or sits beside */
        public array $related = [],
        /** when it becomes a generator or kit recipe */
        public ?string $graduate = null,
    ) {}

    /** Demand: fleet apps × changes plus past apps × changes, 0 without evidence. */
    public function weight(): int
    {
        return $this->evidence === null ? 0 : $this->evidence['apps'] * $this->evidence['count'] + ($this->evidence['past_apps'] ?? 0) * ($this->evidence['past_count'] ?? 0);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['type' => 'leaf', 'id' => $this->id, 'recipe' => $this->recipe, 'summary' => $this->summary, 'kit' => $this->kit, 'make' => $this->make, 'checks' => $this->checks, 'paths' => $this->paths, 'keywords' => $this->keywords, 'status' => $this->status, 'recipe_make' => $this->recipeMake, 'evidence' => $this->evidence, 'components' => $this->components, 'rules' => $this->rules, 'related' => $this->related, 'graduate' => $this->graduate];
    }
}
