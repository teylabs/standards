<?php

declare(strict_types=1);

namespace Tey\Standards\Patterns;

/** A feature composes unit leaves into a build plan. */
final readonly class Feature
{
    /**
     * @param  list<string>  $keywords
     * @param  list<array{id: string, question: string, options: list<string>}>  $questions
     * @param  list<array{unit_leaf_id: string, role: string, when?: string}>  $composition
     */
    public function __construct(
        public string $id,
        public string $summary,
        public array $keywords,
        public array $questions,
        public array $composition,
    ) {}
}
