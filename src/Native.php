<?php

declare(strict_types=1);

namespace Tey\Standards;

use Pest\Arch\Contracts\ArchExpectation;
use Pest\Arch\GroupArchExpectation;
use Pest\Arch\Objects\FunctionDescription;
use Pest\Arch\Objects\VendorObjectDescription;
use Pest\Arch\SingleArchExpectation;
use Pest\ArchPresets\AbstractPreset;
use Pest\TestSuite;
use PHPUnit\Architecture\Elements\ObjectDescription;
use PHPUnit\Framework\AssertionFailedError;
use ReflectionProperty;

/**
 * Pest 4's lazy expectations have no public result API. Keep this version-bound
 * adapter separate, drain every leaf (including after a failure), and never
 * leave failing expectations to throw from a destructor during unwinding.
 */
final class Native
{
    /** @return list<string> */
    public static function failures(ArchExpectation $expectation, ?string $onlyClass = null): array
    {
        if ($onlyClass !== null) {
            $expectation->mergeExcludeCallbacks([fn (ObjectDescription $object): bool => $object->name !== $onlyClass]);
        }

        if ($expectation instanceof GroupArchExpectation) {
            $children = (new ReflectionProperty($expectation, 'expectations'))->getValue($expectation);
            $failures = [];

            foreach ($children as $child) {
                $failures = [...$failures, ...self::failures($child)];
            }

            return $failures;
        }

        if (! $expectation instanceof SingleArchExpectation) {
            throw new \LogicException('Unsupported Pest architecture expectation. Use Pest 4.');
        }

        try {
            $expectation->ensureLazyExpectationIsVerified();

            return [];
        } catch (AssertionFailedError $error) {
            return [$error->getMessage()];
        }
    }

    /**
     * @param  class-string<AbstractPreset>  $type
     * @param  list<string>  $namespaces
     * @param  array<string, string>  $classes  absolute source path => class
     * @return list<array{target: string, check: string}>
     */
    public static function preset(string $type, array $namespaces, array $classes): array
    {
        $failures = [];
        $ignored = [];

        do {
            $found = false;
            $preset = new $type($namespaces);
            $preset->execute();
            $expectations = (new ReflectionProperty(AbstractPreset::class, 'expectations'))->getValue($preset);
            $leaves = [];

            foreach ($expectations as $expectation) {
                if ($expectation instanceof ArchExpectation) {
                    $leaves = [...$leaves, ...self::leaves($expectation)];
                }
            }

            foreach ($leaves as $index => $leaf) {
                $exclude = $ignored[$index] ?? [];
                $scope = array_values($classes);
                $leaf->mergeExcludeCallbacks([fn (ObjectDescription $object): bool => in_array($object->name, $exclude, true)
                    || (! in_array($object->name, $scope, true) && ! $object instanceof FunctionDescription && ! $object instanceof VendorObjectDescription)]);

                try {
                    $leaf->ensureLazyExpectationIsVerified();
                } catch (AssertionFailedError $error) {
                    if (! method_exists($error, 'toCollisionEditor')) {
                        throw $error;
                    }

                    $path = $error->toCollisionEditor()->getFile();
                    $absolute = realpath($path) ?: realpath(TestSuite::getInstance()->rootPath.'/'.$path);
                    $target = $classes[$absolute] ?? null;

                    if ($target === null) {
                        throw new \LogicException("Preset failure outside configured class roots [{$path}]. Add its source root, do not ignore it.", previous: $error);
                    }

                    $ignored[$index][] = $target;
                    $failures[] = ['target' => $target, 'check' => $error->getMessage()];
                    $found = true;
                }
            }
        } while ($found);

        return array_values(array_unique($failures, SORT_REGULAR));
    }

    /** @return list<SingleArchExpectation> */
    private static function leaves(ArchExpectation $expectation): array
    {
        if ($expectation instanceof SingleArchExpectation) {
            return [$expectation];
        }

        if (! $expectation instanceof GroupArchExpectation) {
            throw new \LogicException('Unsupported Pest architecture expectation. Use Pest 4.');
        }

        $leaves = [];

        foreach ((new ReflectionProperty($expectation, 'expectations'))->getValue($expectation) as $child) {
            $leaves = [...$leaves, ...self::leaves($child)];
        }

        return $leaves;
    }
}
