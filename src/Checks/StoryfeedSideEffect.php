<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Tey\Standards\Configuration;
use Tey\Standards\Source;

/**
 * Rule `events.storyfeed-side-effect` (warn only): Storyfeed is a side effect, never the source (Jasper, Nov 22; Storyfeed's guidance, storyfeed#280). A
 * domain event drives the business work, and Storyfeed is one more subscriber of it. App code that listens to Storyfeed's ActivityPublished or
 * ActivityDeleted is reported, and so is an inline publish (`Storyfeed::activity()->…->publish()`, `Storyfeed::publish()`) in an `Actions/` file. Listening is: a method taking an
 * ActivityPublished parameter (a listener's handle(), a closure), an `Event::listen(ActivityPublished::class, …)` and a subscriber mapping it. A listener
 * that only shapes the feed itself (its presentation) carries a `@feed-presentation <reason>` docblock tag on its class and is not reported.
 */
final readonly class StoryfeedSideEffect
{
    public const RULE = 'events.storyfeed-side-effect';

    /** Storyfeed's own events, which fire only when a feed row is written (not while recording is off). */
    public const EVENTS = ['Storyfeed\\Events\\ActivityPublished', 'Storyfeed\\Events\\ActivityDeleted'];

    public const FACADE = 'Storyfeed\\Facades\\Storyfeed';

    public const MARKER = '@feed-presentation';

    private const HINT = 'Dispatch a domain event where the work happens and listen to that; Storyfeed publishes from the same event. Only a listener that shapes the feed itself stays, marked `@feed-presentation <reason>` on its class.';

    public function __construct(private Configuration $config, private Source $source) {}

    /** @return list<array{target: string, check: string, line: int, hint: string}> */
    public function violations(): array
    {
        $violations = [];
        foreach ($this->source->files($this->config->sourceRoots) as $path => $text) {
            $action = preg_match('~(^|/)Actions/~', $path) === 1;
            if (! str_contains($text, 'ActivityPublished') && ! str_contains($text, 'ActivityDeleted') && ! ($action && str_contains($text, 'publish'))) {
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
                if (! in_array($name->toString(), self::EVENTS, true) || $parent instanceof Node\UseItem || $parent instanceof Node\Stmt\Use_
                    || array_filter($exempt, fn (array $range): bool => $line >= $range[0] && $line <= $range[1]) !== []) {
                    continue;
                }
                $event = $name->getLast();
                $how = match (true) {
                    $parent instanceof Node\Param => "a listener takes Storyfeed's {$event}",
                    $parent instanceof Node\Expr\ClassConstFetch && $this->registers($parent) => "registers a listener on Storyfeed's {$event}",
                    default => null,
                };
                if ($how !== null) {
                    $violations[] = ['target' => $path, 'check' => $how.': business work should follow a domain event, with Storyfeed as one subscriber', 'line' => $line, 'hint' => self::HINT];
                }
            }
            // An Action is a domain transition: it dispatches its domain event (PublishesToFeed), and the feed publishes from it.
            foreach ($action ? $finder->find($this->source->nodes($path), fn (Node $node): bool => $this->inlinePublish($node)) : [] as $call) {
                $violations[] = ['target' => $path, 'check' => 'publishes to Storyfeed inline in an Action: dispatch a domain event that implements PublishesToFeed instead', 'line' => $call->getStartLine(),
                    'hint' => 'Dispatch your own domain event where the transition succeeds and implement Storyfeed\\Contracts\\PublishesToFeed, returning the activity from toFeedActivity() without ->publish(). Inline publishing is for imports, backfills, console commands and one-off records, outside Actions.'];
            }
        }

        return $violations;
    }

    /** `Storyfeed::publish(...)`, or `->publish()` on a chain that starts at the Storyfeed facade (`Storyfeed::activity()->…->publish()`). */
    private function inlinePublish(Node $node): bool
    {
        if ($node instanceof Node\Expr\StaticCall) {
            return $node->class instanceof Node\Name && $node->class->toString() === self::FACADE && $node->name instanceof Node\Identifier && $node->name->toString() === 'publish';
        }
        if (! $node instanceof Node\Expr\MethodCall || ! $node->name instanceof Node\Identifier || $node->name->toString() !== 'publish') {
            return false;
        }
        $root = $node->var;
        while ($root instanceof Node\Expr\MethodCall) {
            $root = $root->var;
        }

        return $root instanceof Node\Expr\StaticCall && $root->class instanceof Node\Name && $root->class->toString() === self::FACADE;
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
