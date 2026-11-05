<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Tey\Standards\Configuration;
use Tey\Standards\Source;

/**
 * [vm.shaped-arrays] A ViewModel prop that returns an array says what is in it: `@return list<array{...}>`, `array<string, X>` or a shape. A bare
 * `array` is generated as `any`/`Record<string, any>`, so the page loses its types. Advisory until an app opts in.
 */
final class ViewModelShapedArrays
{
    public function __construct(private readonly Configuration $config, private readonly Source $source) {}

    /** @return list<array{target: string, check: string}> */
    public function violations(): array
    {
        $violations = [];
        foreach ($this->source->files($this->config->viewModelRoots) as $path => $contents) {
            if (! str_ends_with(basename($path, '.php'), $this->config->viewModelSuffix)) {
                continue;
            }
            $unshaped = [];
            foreach ((new NodeFinder)->findInstanceOf($this->source->nodes($path), Node\Stmt\ClassMethod::class) as $method) {
                if (! $method->isPublic() || $method->isStatic() || str_starts_with($method->name->toString(), '__') || ! self::returnsArray($method->returnType)) {
                    continue;
                }
                $doc = (string) $method->getDocComment()?->getText();
                if (preg_match('/@(?:phpstan-|psalm-)?return\s+[^\s*]*[<{]/', $doc) !== 1) {
                    $unshaped[] = $method->name->toString();
                }
            }
            foreach ($unshaped as $name) {
                $violations[] = ['target' => $path, 'check' => 'array prop without a @return shape: '.$name.'()'];
            }
        }

        return $violations;
    }

    private static function returnsArray(?Node $type): bool
    {
        return match (true) {
            $type instanceof Node\Identifier => strtolower($type->toString()) === 'array',
            $type instanceof Node\NullableType => self::returnsArray($type->type),
            default => false,
        };
    }
}
