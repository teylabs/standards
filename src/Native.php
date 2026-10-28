<?php

declare(strict_types=1);

namespace Tey\Standards;

use Composer\InstalledVersions;
use Pest\Arch\Concerns\Architectable;
use Pest\Arch\Contracts\ArchExpectation;
use Pest\Arch\GroupArchExpectation;
use Pest\Arch\Objects\FunctionDescription;
use Pest\Arch\Objects\VendorObjectDescription;
use Pest\Arch\SingleArchExpectation;
use Pest\ArchPresets\AbstractPreset;
use Pest\TestSuite;
use PHPUnit\Architecture\Elements\ObjectDescription;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Pest 4 and 5's lazy expectations have no public result API. Keep this
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
            throw new \LogicException('Unsupported Pest architecture expectation. Use Pest 4 (4.7+) or Pest 5.');
        }

        try {
            self::verify($expectation);

            return [];
        } catch (AssertionFailedError $error) {
            return [self::diagnostic($error)];
        }
    }

    /**
     * @param  class-string<AbstractPreset>  $type
     * @param  list<string>  $namespaces
     * @param  list<string>  $ignoring  namespaces left out of the preset (Configuration::$presetIgnores, for the Laravel preset)
     * @param  array<string, string>  $classes  absolute source path => class
     * @return list<array{target: string, check: string}>
     */
    public static function preset(string $type, array $namespaces, array $classes, array $ignoring = []): array
    {
        $failures = [];
        $ignored = [];
        // Building the preset already asks the running test for its arch options, so stand in for the whole run.
        $suite = TestSuite::getInstance();
        $running = $suite->test;
        $suite->test = self::architectable($running);

        try {
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
                    $scope = array_values(array_filter($classes, fn (string $class): bool => array_filter($ignoring, fn (string $namespace): bool => str_starts_with($class, rtrim($namespace, '\\').'\\')) === []));
                    // A vendor package can map the app's own namespace to its folder (laravel/pint and laravel/cloud-cli map App\ to theirs). Pest describes
                    // those classes as vendor objects with no path, and a preset expectation that fails on one has nothing to report and throws. They aren't
                    // the app's code: leave vendor objects inside the app's namespaces out, and keep vendor targets elsewhere (Illuminate, say) as before.
                    $leaf->mergeExcludeCallbacks([fn (ObjectDescription $object): bool => in_array($object->name, $exclude, true)
                        || ($object instanceof VendorObjectDescription && self::within($object->name, $namespaces))
                        || (! in_array($object->name, $scope, true) && ! $object instanceof FunctionDescription && ! $object instanceof VendorObjectDescription)]);

                    try {
                        self::verify($leaf);
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
                        $failures[] = ['target' => $target, 'check' => self::diagnostic($error)];
                        $found = true;
                    }
                }
            } while ($found);
        } finally {
            $suite->test = $running;
        }

        return array_values(array_unique($failures, SORT_REGULAR));
    }

    /** @param  list<string>  $namespaces */
    private static function within(string $class, array $namespaces): bool
    {
        foreach ($namespaces as $namespace) {
            if (str_starts_with($class, rtrim($namespace, '\\').'\\')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pest only verifies a lazy expectation while a test is running; outside one
     * (standards:check) it is a silent pass. Stand a test in so the gate fails
     * the same expectations the Pest suite does, and restore the suite after.
     */
    private static function verify(SingleArchExpectation $expectation): void
    {
        $suite = TestSuite::getInstance();
        $running = $suite->test;

        $suite->test = self::architectable($running);

        try {
            $expectation->ensureLazyExpectationIsVerified();
        } finally {
            $suite->test = $running;
        }
    }

    /** The running test when it carries Pest's arch trait; otherwise a stand-in that does. */
    private static function architectable(?TestCase $running): TestCase
    {
        // A running test without the trait (one outside the root Pest applies it to) cannot verify either.
        return $running instanceof TestCase && method_exists($running, 'arch') ? $running : new class('standards') extends TestCase
        {
            use Architectable;
        };
    }

    /** Preserve exact ratchet keys when Pest 5 brackets targeted expectation arguments. */
    private static function diagnostic(AssertionFailedError $error): string
    {
        $message = $error->getMessage();

        if (! str_starts_with(InstalledVersions::getVersion('pestphp/pest') ?? '', '5.')) {
            return $message;
        }

        return preg_replace_callback('/(to (?:extend|have (?:method|suffix|attribute)) )((?:\[[^\]]*\])(?:, \[[^\]]*\])*)\.$/',
            fn (array $match): string => $match[1].str_replace(['[', ']'], "'", $match[2]).'.', $message) ?? $message;
    }

    /** @return list<SingleArchExpectation> */
    private static function leaves(ArchExpectation $expectation): array
    {
        if ($expectation instanceof SingleArchExpectation) {
            return [$expectation];
        }

        if (! $expectation instanceof GroupArchExpectation) {
            throw new \LogicException('Unsupported Pest architecture expectation. Use Pest 4 (4.7+) or Pest 5.');
        }

        $leaves = [];

        foreach ((new ReflectionProperty($expectation, 'expectations'))->getValue($expectation) as $child) {
            $leaves = [...$leaves, ...self::leaves($child)];
        }

        return $leaves;
    }
}
