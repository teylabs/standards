<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Tey\Standards\Configuration;
use Tey\Standards\Source;

/**
 * Rule `events.storyfeed-side-effect` (warn only): Storyfeed is a side effect, never the source (Jasper, Nov 22; Storyfeed's guidance, storyfeed#280). A
 * domain event drives the business work, and Storyfeed is one more subscriber of it. App code that listens to Storyfeed's ActivityPublished,
 * ActivityDeleted or BatchClosed is reported (they fire only when feed rows change: not while recording is off, under Storyfeed::fake(), or for an
 * idempotent duplicate). The Snapshots payload types are not events and are not flagged. Also reported: an inline publish (`Storyfeed::activity()->…->publish()`, `Storyfeed::publish()`, `Storyfeed::record()`) in an `Actions/` file. Listening is: a method taking an
 * ActivityPublished parameter (a listener's handle(), a closure), an `Event::listen(ActivityPublished::class, …)` and a subscriber mapping it. A listener
 * that only shapes the feed itself (its presentation) carries a `@feed-presentation <reason>` docblock tag on its class and is not reported.
 */
final readonly class StoryfeedSideEffect
{
    public const RULE = 'events.storyfeed-side-effect';

    /** Storyfeed's own events, which fire only when a feed row is written (not while recording is off). */
    public const EVENTS = ['Storyfeed\\Events\\ActivityPublished', 'Storyfeed\\Events\\ActivityDeleted', 'Storyfeed\\Events\\BatchClosed'];

    public const FACADE = 'Storyfeed\\Facades\\Storyfeed';

    public const MARKER = '@feed-presentation';

    /** Storyfeed's own doctor check `listeners.business` (storyfeed v0.30.5) says the same, so the rule and core agree. */
    private const HINT = 'Storyfeed is a side effect, never the source: that event fires only when a feed row is written, so not while recording is off, under Storyfeed::fake(), or for an idempotent duplicate. Move business logic (access, billing, subscriptions, mail, notification routing) to a listener on your own domain event, and publish to the feed from that event with PublishesToFeed. Keep this listener only if it presents the feed (a cache bust, a live broadcast), and mark its class `@feed-presentation <reason>`.';

    public function __construct(private Configuration $config, private Source $source) {}

    /** @return list<array{target: string, check: string, line: int, hint: string}> */
    public function violations(): array
    {
        $violations = [];
        foreach ($this->source->files($this->config->sourceRoots) as $path => $text) {
            $action = preg_match('~(^|/)Actions/~', $path) === 1 && ! $this->backfill($path, $text);
            if (preg_match('~ActivityPublished|ActivityDeleted|BatchClosed~', $text) !== 1 && ! ($action && preg_match('~publish|record~', $text) === 1)) {
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
                    $violations[] = ['target' => $path, 'check' => $how.': Storyfeed is a side effect, never the source', 'line' => $line, 'hint' => self::HINT];
                }
            }
            // An Action is a domain transition: it dispatches its domain event (PublishesToFeed), and the feed publishes from it.
            foreach ($action ? $finder->find($this->source->nodes($path), fn (Node $node): bool => $this->inlinePublish($node)) : [] as $call) {
                $violations[] = ['target' => $path, 'check' => 'publishes to Storyfeed inline in an Action: Storyfeed is a side effect, never the source; dispatch a domain event that implements PublishesToFeed', 'line' => $call->getStartLine(),
                    'hint' => 'Dispatch your own domain event where the transition succeeds and implement Storyfeed\\Contracts\\PublishesToFeed: its toFeedActivity() returns the activity, or the Story\'s toFeedActivity(), without ->publish(), and core\'s listener publishes it. Inline publishing stays for imports and backfills: console commands, seeders and migrations.'];
            }
        }

        return $violations;
    }

    /** Imports and backfills, where publishing inline is still correct: a console command, a seeder or a migration (by folder or by the class it extends). */
    private function backfill(string $path, string $text): bool
    {
        return preg_match('~(^|/)(Console|Commands)/|(^|/)database/(seeders|migrations)/~', $path) === 1
            || preg_match('~\\bextends\\s+\\\\?(?:[\\w\\\\]*\\\\)?(?:Command|Seeder|Migration)\\b~', $text) === 1;
    }

    /** `Storyfeed::publish(...)` or `Storyfeed::record(...)`, or `->publish()` on a chain that starts at the Storyfeed facade (`Storyfeed::activity()->…->publish()`). */
    private function inlinePublish(Node $node): bool
    {
        if ($node instanceof Node\Expr\StaticCall) {
            return $node->class instanceof Node\Name && $node->class->toString() === self::FACADE && $node->name instanceof Node\Identifier && in_array($node->name->toString(), ['publish', 'record'], true);
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
