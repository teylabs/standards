<?php

declare(strict_types=1);

namespace Tey\Standards\Patterns;

final readonly class Branch
{
    /** @param list<array{answer: string, next: string}> $options */
    public function __construct(
        public string $id,
        public string $question,
        public array $options,
        public ?string $why = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter(['type' => 'question', 'id' => $this->id, 'question' => $this->question, 'why' => $this->why, 'options' => $this->options], fn (mixed $value): bool => $value !== null);
    }
}
