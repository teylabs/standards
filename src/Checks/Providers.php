<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use PhpParser\Node;
use PhpParser\NodeFinder;
use ReflectionClass;
use Tey\Standards\Configuration;
use Tey\Standards\Source;
use Throwable;

final class Providers
{
    public function __construct(private readonly Configuration $config, private readonly Source $source) {}

    /** @return list<array{target: string, check: string}> */
    public function violations(): array
    {
        $path = 'bootstrap/providers.php';
        if (! is_file($this->config->base.'/'.$path)) {
            return [];
        }
        $manifest = $this->json('composer.json');
        $lock = $this->json('composer.lock');
        $dev = [];
        $runtime = $this->prefixes($manifest['autoload'] ?? []);
        foreach ($lock['packages-dev'] ?? [] as $package) {
            $dev = [...$dev, ...$this->prefixes($package['autoload'] ?? [])];
        }
        foreach ($lock['packages'] ?? [] as $package) {
            $runtime = [...$runtime, ...$this->prefixes($package['autoload'] ?? [])];
        }
        $dev = [...$dev, ...$this->prefixes($manifest['autoload-dev'] ?? [])];
        $violations = [];
        foreach ((new NodeFinder)->findInstanceOf($this->source->nodes($path), Node\Stmt\Return_::class) as $return) {
            if (! $return->expr instanceof Node\Expr\Array_) {
                $violations[] = ['target' => $path, 'check' => 'provider list must be a literal array'];

                continue;
            }
            foreach ($return->expr->items as $item) {
                $value = $item->value;
                $class = $value instanceof Node\Expr\ClassConstFetch && $value->class instanceof Node\Name && $value->name instanceof Node\Identifier && strtolower($value->name->toString()) === 'class'
                    ? $value->class->toString() : ($value instanceof Node\Scalar\String_ ? ltrim($value->value, '\\') : null);
                if ($class === null || $item->unpack) {
                    $violations[] = ['target' => $path, 'check' => 'provider entry must be a literal class'];

                    continue;
                }
                $devPrefix = $this->longestPrefix($class, $dev);
                $runtimePrefix = $this->longestPrefix($class, $runtime);
                $devPackage = $this->fromDevPackage($class, $manifest, $lock);
                if (($devPrefix > 0 && $devPrefix > $runtimePrefix) || $devPackage) {
                    $violations[] = ['target' => $path, 'check' => $class.' belongs to require-dev'];

                    continue;
                }
                try {
                    $exists = class_exists($class);
                } catch (Throwable) {
                    $exists = false;
                }
                if (! $exists) {
                    $violations[] = ['target' => $path, 'check' => $class.' is not autoloadable'];
                }
            }
        }

        return array_values(array_unique($violations, SORT_REGULAR));
    }

    /** @return array<string, mixed> */
    private function json(string $path): array
    {
        return is_file($this->config->base.'/'.$path) ? json_decode((string) file_get_contents($this->config->base.'/'.$path), true, flags: JSON_THROW_ON_ERROR) : [];
    }

    /** @param array<string, mixed> $autoload
     * @return list<string>
     */
    private function prefixes(array $autoload): array
    {
        return array_values(array_filter([...array_keys($autoload['psr-4'] ?? []), ...array_keys($autoload['psr-0'] ?? [])], is_string(...)));
    }

    /** @param list<string> $prefixes */
    private function longestPrefix(string $class, array $prefixes): int
    {
        $length = 0;
        foreach ($prefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($class, $prefix)) {
                $length = max($length, strlen($prefix));
            }
        }

        return $length;
    }

    /** Classmap and files autoloaders have no namespace metadata. Inspect the resolved file without instantiating the provider.
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $lock
     */
    private function fromDevPackage(string $class, array $manifest, array $lock): bool
    {
        try {
            if (! class_exists($class)) {
                return false;
            }
            $file = (new ReflectionClass($class))->getFileName();
        } catch (Throwable) {
            return false;
        }
        if ($file === false) {
            return false;
        }
        $vendor = $this->config->base.'/'.($manifest['config']['vendor-dir'] ?? 'vendor');
        foreach ($lock['packages-dev'] ?? [] as $package) {
            $directory = realpath($vendor.'/'.$package['name']);
            if ($directory !== false && str_starts_with($file, $directory.DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }
}
