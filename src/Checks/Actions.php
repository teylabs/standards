<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Tey\Standards\Configuration;
use Tey\Standards\Source;

final class Actions
{
    public function __construct(private readonly Configuration $config, private readonly Source $source) {}

    /** @return list<array{target: string, check: string}> */
    public function violations(): array
    {
        $violations = [];

        foreach ($this->source->files($this->config->sourceRoots) as $path => $text) {
            foreach ((new NodeFinder)->findInstanceOf($this->source->nodes($path), Node\Stmt\ClassLike::class) as $class) {
                if (! isset($class->namespacedName)) {
                    continue;
                }

                $namespace = substr($class->namespacedName->toString(), 0, (int) strrpos($class->namespacedName->toString(), '\\'));
                foreach ($this->config->forbiddenNamespaces as $forbidden) {
                    if (str_contains($forbidden, '\\') ? Source::within($namespace, [trim($forbidden, '\\')]) : in_array($forbidden, explode('\\', $namespace), true)) {
                        $violations[] = ['target' => $path, 'check' => 'forbidden backend namespace '.$forbidden];
                    }
                }
            }
        }

        return array_values(array_unique($violations, SORT_REGULAR));
    }
}
