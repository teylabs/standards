<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Tey\Standards\Configuration;
use Tey\Standards\Source;

final readonly class PestOnly
{
    public function __construct(private Configuration $config) {}

    /** @return list<array{target: string, check: string}> */
    public function violations(): array
    {
        $source = new Source($this->config);
        $classes = [];
        $bases = [];
        $violations = [];

        foreach ($source->files(['tests']) as $path => $text) {
            foreach ((new NodeFinder)->findInstanceOf($source->nodes($path), Node\Stmt\Class_::class) as $class) {
                if (isset($class->namespacedName) && $class->extends !== null) {
                    $classes[$class->namespacedName->toString()] = ['node' => $class, 'path' => $path, 'parent' => $class->extends->toString()];
                }
            }

            foreach ((new NodeFinder)->find($source->nodes($path), fn (Node $node): bool => $node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\FuncCall) as $call) {
                if (! $call instanceof Node\Expr\MethodCall && ! $call instanceof Node\Expr\FuncCall) {
                    continue;
                }

                $name = $call->name;
                if (($name instanceof Node\Name || $name instanceof Node\Identifier) && in_array($name->toString(), ['extend', 'uses'], true)) {
                    foreach ($call->getArgs() as $argument) {
                        if ($argument->value instanceof Node\Expr\ClassConstFetch && $argument->value->class instanceof Node\Name
                            && $argument->value->name instanceof Node\Identifier && strtolower($argument->value->name->toString()) === 'class') {
                            $bases[] = $argument->value->class->toString();
                        }
                    }
                }
            }
        }

        foreach ($classes as $name => $entry) {
            $class = $entry['node'];
            $path = $entry['path'];
            $configured = array_any($this->config->testBasePaths, fn (string $pattern): bool => fnmatch($pattern, $path));
            if ($configured || (str_ends_with($path, 'Case.php') && in_array($name, $bases, true))) {
                continue;
            }

            $parent = $entry['parent'];
            $seen = [];
            while (isset($classes[$parent]) && ! isset($seen[$parent])) {
                $seen[$parent] = true;
                $parent = $classes[$parent]['parent'];
            }

            if (! in_array($parent, ['PHPUnit\Framework\TestCase', 'Illuminate\Foundation\Testing\TestCase', 'Laravel\Lumen\Testing\TestCase', 'Orchestra\Testbench\TestCase'], true)) {
                continue;
            }

            foreach ($class->getMethods() as $method) {
                $attributes = (new NodeFinder)->findInstanceOf($method->attrGroups, Node\Attribute::class);
                if (str_starts_with($method->name->toString(), 'test') || array_any($attributes, fn (Node\Attribute $attribute): bool => $attribute->name->toString() === 'PHPUnit\Framework\Attributes\Test')) {
                    $violations[] = ['target' => $path, 'check' => 'PHPUnit test class '.$name.' must use Pest'];
                    break;
                }
            }
        }

        return $violations;
    }
}
