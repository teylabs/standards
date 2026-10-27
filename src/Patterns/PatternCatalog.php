<?php

declare(strict_types=1);

namespace Tey\Standards\Patterns;

/**
 * The pattern decision tree. Callers traverse it from the root question, or
 * match an intent or path to candidate leaves; the storage format stays private
 * to the implementation.
 */
interface PatternCatalog
{
    public function root(): Branch;

    public function node(string $id): Branch|Leaf|null;

    public function parentOf(string $id): ?Branch;

    /** @return list<Leaf> */
    public function leaves(): array;

    /** @return list<Leaf> leaves whose path globs match a project-relative path */
    public function forPath(string $path): array;

    /** @return list<Leaf> leaves ranked by keyword overlap with a free-text intent */
    public function search(string $intent): array;

    /** The first ready leaf whose checks name the rule. */
    public function forRule(string $rule): ?Leaf;
}
