<?php

declare(strict_types=1);

namespace Tey\Standards;

use InvalidArgumentException;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use Symfony\Component\Finder\Finder;

final class Source
{
    /** @var array<string, array<Node>> */
    private array $parsed = [];

    public function __construct(private readonly Configuration $config) {}

    /** @param list<string> $roots
     * @return array<string, string>
     */
    public function files(array $roots, string $pattern = '*.php'): array
    {
        $files = [];

        foreach ($roots as $root) {
            $directories = glob($this->config->base.'/'.$root, GLOB_ONLYDIR) ?: [];

            if ($directories === [] && ! str_contains($root, '*')) {
                throw new InvalidArgumentException("Conformance root [{$root}] does not exist.");
            }

            foreach ($directories as $directory) {
                foreach ((new Finder)->files()->in($directory)->name($pattern)->exclude(['vendor', 'node_modules']) as $file) {
                    $path = str_replace('\\', '/', substr($file->getPathname(), strlen($this->config->base) + 1));
                    $files[$path] = $file->getContents();
                }
            }
        }

        ksort($files);

        return $files;
    }

    /** @return array<Node> */
    public function nodes(string $path): array
    {
        if (! isset($this->parsed[$path])) {
            $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse((string) file_get_contents($this->config->base.'/'.$path)) ?? [];
            $this->parsed[$path] = (new NodeTraverser(new NameResolver, new ParentConnectingVisitor))->traverse($nodes);
        }

        return $this->parsed[$path];
    }

    /** @return array<class-string, string> class => path */
    public function classes(): array
    {
        $classes = [];

        foreach ($this->files(array_keys($this->config->classRoots)) as $path => $source) {
            foreach ((new NodeFinder)->findInstanceOf($this->nodes($path), Node\Stmt\ClassLike::class) as $node) {
                if (isset($node->namespacedName)) {
                    $class = $node->namespacedName->toString();

                    if (! class_exists($class) && ! interface_exists($class) && ! trait_exists($class)) {
                        throw new InvalidArgumentException("Conformance class [{$class}] at [{$path}] cannot be autoloaded: check its PSR-4 placement.");
                    }

                    $classes[$class] = $path;
                }
            }
        }

        if ($classes === []) {
            throw new InvalidArgumentException('Conformance class roots contain no named classes: not verified.');
        }

        return $classes;
    }

    /** @param list<string> $roots */
    public function inside(string $path, array $roots): bool
    {
        foreach ($roots as $root) {
            if (fnmatch(rtrim($root, '/').'/*', $path)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $namespaces */
    public static function within(string $name, array $namespaces): bool
    {
        foreach ($namespaces as $namespace) {
            if ($name === $namespace || str_starts_with($name, $namespace.'\\')) {
                return true;
            }
        }

        return false;
    }
}
