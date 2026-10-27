<?php

declare(strict_types=1);

namespace Tey\Standards\Patterns;

final readonly class Leaf
{
    public const STATUSES = ['ready', 'draft', 'gap'];

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
        /** @var array{apps: int, count: int, prs: int}|null counts only */
        public ?array $evidence = null,
    ) {}

    /** Gap priority: apps × count from the evidence, 0 without it. */
    public function weight(): int
    {
        return $this->evidence === null ? 0 : $this->evidence['apps'] * $this->evidence['count'];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['type' => 'leaf', 'id' => $this->id, 'recipe' => $this->recipe, 'summary' => $this->summary, 'kit' => $this->kit, 'make' => $this->make, 'checks' => $this->checks, 'paths' => $this->paths, 'keywords' => $this->keywords, 'status' => $this->status, 'recipe_make' => $this->recipeMake, 'evidence' => $this->evidence];
    }
}
