<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\NodeTraverser;
use Tey\Standards\Configuration;
use Tey\Standards\Source;

/**
 * `media.image-scoped` (warn only): Laravel binds `image` scoped, so a queue worker (or Octane) builds a new ImageManager for every job. A custom
 * transformation or driver registered once (`Image::transformUsing()`, `Image::extend()`, or the same on an ImageManager or `app('image')`) lands on the
 * first manager only: the worker's first job works and every later one refuses it. Register it in a callback run for every manager:
 * `$this->callAfterResolving('image', fn (ImageManager $images) => $images->transformUsing(...))` (also `afterResolving` or `resolving`).
 *
 * Reported: a `transformUsing` or `extend` call on the `Image` facade anywhere, and on any other receiver in a service provider, unless it sits inside a
 * closure passed to callAfterResolving, afterResolving or resolving. Not covered: a manager held in a property and configured from another class.
 */
final class ImageScoped
{
    private const METHODS = ['transformusing', 'extend'];

    private const RESOLVING = ['callafterresolving', 'afterresolving', 'resolving'];

    /** @return list<array{target: string, check: string, line: int, hint: string, advisory: bool}> */
    public static function violations(Configuration $config): array
    {
        $source = new Source($config);
        $finder = new NodeFinder;
        $found = [];
        foreach ($source->files($config->sourceRoots) as $path => $text) {
            if (! str_contains($text, 'transformUsing') && ! (str_contains($text, 'extend') && (str_contains($text, 'Image') || str_contains($text, "'image'")))) {
                continue;
            }
            $nodes = $source->nodes($path);
            $traverser = new NodeTraverser(new ParentConnectingVisitor);
            $nodes = $traverser->traverse($nodes);
            $provider = str_contains($text, 'ServiceProvider');
            $calls = $finder->find($nodes, fn (Node $node): bool => ($node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall)
                && $node->name instanceof Node\Identifier && in_array($node->name->toLowerString(), self::METHODS, true));
            foreach ($calls as $call) {
                $facade = $call instanceof Node\Expr\StaticCall && $call->class instanceof Node\Name && $call->class->getLast() === 'Image';
                $onImage = $call instanceof Node\Expr\StaticCall ? $facade : self::imageReceiver($call->var);
                if (! $facade && ! ($provider && ($onImage || $call->name->toLowerString() === 'transformusing'))) {
                    continue;
                }
                if (self::insideResolving($call)) {
                    continue;
                }
                $found[] = ['target' => $path, 'check' => $call->name->toString().'() on the image manager outside a resolving callback', 'line' => $call->getStartLine(), 'advisory' => true,
                    'hint' => "Laravel binds 'image' scoped: a queue worker builds a new ImageManager for every job, so this lands on the first job's manager only and later jobs refuse the transformation. Register it with \$this->callAfterResolving('image', fn (ImageManager \$images) => \$images->".$call->name->toString().'(...)) (kit docs/images.md).'];
            }
        }

        return $found;
    }

    /** `app('image')`, `$this->app['image']`, `resolve('image')`, or a variable or property named images/image/manager. */
    private static function imageReceiver(Node\Expr $receiver): bool
    {
        if ($receiver instanceof Node\Expr\FuncCall && $receiver->name instanceof Node\Name && in_array($receiver->name->toLowerString(), ['app', 'resolve'], true)) {
            $first = $receiver->getArgs()[0]->value ?? null;

            return $first instanceof Node\Scalar\String_ && $first->value === 'image' || $first instanceof Node\Expr\ClassConstFetch && $first->class instanceof Node\Name && $first->class->getLast() === 'ImageManager';
        }
        if ($receiver instanceof Node\Expr\ArrayDimFetch) {
            return $receiver->dim instanceof Node\Scalar\String_ && $receiver->dim->value === 'image';
        }
        if ($receiver instanceof Node\Expr\MethodCall && $receiver->name instanceof Node\Identifier && $receiver->name->toLowerString() === 'make') {
            $first = $receiver->getArgs()[0]->value ?? null;

            return $first instanceof Node\Scalar\String_ && $first->value === 'image';
        }

        return false;
    }

    private static function insideResolving(Node $node): bool
    {
        for ($at = $node->getAttribute('parent'); $at !== null; $at = $at->getAttribute('parent')) {
            if (($at instanceof Node\Expr\Closure || $at instanceof Node\Expr\ArrowFunction) && ($arg = $at->getAttribute('parent')) instanceof Node\Arg) {
                $call = $arg->getAttribute('parent');
                if (($call instanceof Node\Expr\MethodCall || $call instanceof Node\Expr\StaticCall) && $call->name instanceof Node\Identifier && in_array($call->name->toLowerString(), self::RESOLVING, true)) {
                    return true;
                }
            }
        }

        return false;
    }
}
