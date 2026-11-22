<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Tey\Standards\Configuration;
use Tey\Standards\Source;

/**
 * Rule `events.storyfeed-side-effect` (warn only): Storyfeed is a side effect of the domain, not its source (Jasper, Nov 22). A domain event drives the
 * business work, and Storyfeed is one more subscriber of it. App code that listens to Storyfeed's ActivityPublished is reported: a method taking an
 * ActivityPublished parameter (a listener's handle(), a closure), an `Event::listen(ActivityPublished::class, …)` and a subscriber mapping it. A listener
 * that only shapes the feed itself (its presentation) carries a `@feed-presentation <reason>` docblock tag on its class and is not reported.
 */
final readonly class StoryfeedSideEffect
{
    public const RULE = 'events.storyfeed-side-effect';

    public const EVENT = 'Storyfeed\\Events\\ActivityPublished';

    public const MARKER = '@feed-presentation';

    private const HINT = 'Dispatch a domain event where the work happens and listen to that; Storyfeed publishes from the same event. Only a listener that shapes the feed itself stays, marked `@feed-presentation <reason>` on its class.';

    public function __construct(private Configuration $config, private Source $source) {}

    /** @return list<array{target: string, check: string, line: int, hint: string}> */
    public function violations(): array
    {
        $violations = [];
        foreach ($this->source->files($this->config->sourceRoots) as $path => $text) {
            if (! str_contains($text, 'ActivityPublished')) {
                continue;
            }
            $finder = new NodeFinder;
            $exempt = [];
            foreach ($finder->findInstanceOf($this->source->nodes($path), Node\Stmt\Class_::class) as $class) {
                $doc = (string) $class->getDocComment()?->getText();
                if (preg_match('~(?:^|\s)'.preg_quote(self::MARKER, '~').'[ \t]+(?!\*/)\S~m', $doc) === 1) {
                    $exempt[] = [$class->getStartLine(), $class->getEndLine()];
                }
            }
            foreach ($finder->findInstanceOf($this->source->nodes($path), Node\Name::class) as $name) {
                $parent = $name->getAttribute('parent');
                $line = $name->getStartLine();
                if ($name->toString() !== self::EVENT || $parent instanceof Node\UseItem || $parent instanceof Node\Stmt\Use_
                    || array_filter($exempt, fn (array $range): bool => $line >= $range[0] && $line <= $range[1]) !== []) {
                    continue;
                }
                $how = match (true) {
                    $parent instanceof Node\Param => 'a listener takes Storyfeed\'s ActivityPublished',
                    $parent instanceof Node\Expr\ClassConstFetch && $this->registers($parent) => 'registers a listener on Storyfeed\'s ActivityPublished',
                    default => null,
                };
                if ($how !== null) {
                    $violations[] = ['target' => $path, 'check' => $how.': business work should follow a domain event, with Storyfeed as one subscriber', 'line' => $line, 'hint' => self::HINT];
                }
            }
        }

        return $violations;
    }

    /** `ActivityPublished::class` as a `listen(...)` argument, or as a key in a subscriber's `subscribe()` / `$listen` map. Dispatching it is not listening. */
    private function registers(Node\Expr\ClassConstFetch $fetch): bool
    {
        $up = $fetch->getAttribute('parent');
        if ($up instanceof Node\Arg) {
            $call = $up->getAttribute('parent');

            return ($call instanceof Node\Expr\StaticCall || $call instanceof Node\Expr\MethodCall) && $call->name instanceof Node\Identifier && strtolower($call->name->toString()) === 'listen';
        }

        return $up instanceof Node\ArrayItem && $up->key === $fetch;
    }
}
