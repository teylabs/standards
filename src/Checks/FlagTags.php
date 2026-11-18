<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Tey\Standards\Configuration;
use Tey\Standards\Source;

/**
 * Two warn-only rules for tey/kit's flags and tags (kit docs/flags.md).
 *
 * - `flags.via-api`: a flag is raised and removed through HasFlags (`flag()`, `unflag()`, `dismissFlag()`), which keeps its reason and link and respects a
 *   dismissal. A call to spatie/laravel-tags' own writers (attachTag, attachTags, detachTag, detachTags, syncTagsWithType, findOrCreate) with the type
 *   `flag` (the string, or Flags::TYPE) bypasses both. Active when the app requires tey/kit.
 * - `ui.tag-list`: a record's flags and tags show through the kit's TagList, not a hand-rolled row of kit Badges: a `<Badge>` repeated with v-for, or the
 *   first child of an element repeated with v-for, in an app .vue file.
 */
final class FlagTags
{
    private const WRITERS = ['attachtag', 'attachtags', 'detachtag', 'detachtags', 'synctagswithtype', 'findorcreate'];

    /** @return list<array{target: string, check: string, line?: int, hint?: string, advisory?: bool}> */
    public static function viaApi(Configuration $config, Source $source): array
    {
        $composer = is_file($config->base.'/composer.json') ? json_decode((string) file_get_contents($config->base.'/composer.json'), true) : null;
        if (! is_array($composer) || ! isset($composer['require']['tey/kit'])) {
            return [];
        }
        $violations = [];
        foreach ($source->files($config->sourceRoots) as $path => $text) {
            if (! str_contains($text, 'flag')) {
                continue;
            }
            $calls = (new NodeFinder)->find($source->nodes($path), fn (Node $node): bool => ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\NullsafeMethodCall)
                && $node->name instanceof Node\Identifier && in_array($node->name->toLowerString(), self::WRITERS, true));
            foreach ($calls as $call) {
                foreach ($call->getArgs() as $argument) {
                    if (self::isFlagType($argument->value)) {
                        $violations[] = ['target' => $path, 'check' => $call->name->toString().'() writes a flag-typed tag directly', 'line' => $call->getStartLine(), 'advisory' => true,
                            'hint' => 'Raise and remove flags through HasFlags: $record->flag($key, reason: ..., link: ..., when: ...) and $record->unflag($key). They keep the reason and link, and a dismissed flag ("Not a duplicate") is not raised again.'];
                        break;
                    }
                }
            }
        }

        return $violations;
    }

    /** @return list<array{target: string, check: string, line?: int, hint?: string, advisory?: bool}> */
    public static function tagList(Configuration $config): array
    {
        $violations = [];
        foreach ((new FrontendSource($config))->appFiles() as $path => $text) {
            if (! str_ends_with($path, '.vue') || ! str_contains($text, 'Badge')) {
                continue;
            }
            $template = FrontendSource::template($text);
            $patterns = ['~<Badge\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*\sv-for=~', '~<[\w.-]+\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*\sv-for=(?:"[^"]*"|\'[^\']*\')(?:[^>"\']|"[^"]*"|\'[^\']*\')*>\s*<Badge\b~'];
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $template, $match, PREG_OFFSET_CAPTURE) === 1) {
                    $line = substr_count(substr($template, 0, (int) $match[0][1]), "\n") + 1;
                    $violations[] = ['target' => $path, 'check' => 'a hand-rolled row of Badges: use the kit\'s TagList', 'line' => $line, 'advisory' => true,
                        'hint' => 'Show a record\'s flags and tags with <TagList :tags="row.tags" :label="..." /> from @kit/ui, built on the server with Tags::for($records) (kit docs/flags.md): flags first, wrapping, "+N more" only past eight, each flag\'s reason and link kept.'];
                    break;
                }
            }
        }

        return $violations;
    }

    private static function isFlagType(Node\Expr $value): bool
    {
        return ($value instanceof Node\Scalar\String_ && $value->value === 'flag')
            || ($value instanceof Node\Expr\ClassConstFetch && $value->class instanceof Node\Name && str_ends_with($value->class->toString(), 'Flags') && $value->name instanceof Node\Identifier && $value->name->toString() === 'TYPE');
    }
}
