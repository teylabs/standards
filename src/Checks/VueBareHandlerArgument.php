<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use Tey\Standards\Configuration;

/**
 * A native DOM event bound to a bare handler name passes the Event as the handler's first argument. `@click="begin"` on a `<button>` (or on a kit
 * `Button`/`Link`, whose click falls through to the native element) calls `begin(event)`, so a handler that takes something else first, such as
 * `begin(url = props.start.url)`, receives a PointerEvent in its place. Found on native elements (a lowercase tag) and kit Button/Link: a custom
 * component's own events carry the payload the component emits, so they are not judged. A first parameter typed as an Event, or named `e`, `event` or
 * `evt` without a type, is fine. Call it explicitly instead: `@click="begin()"`. Files tey/kit owns are not scanned.
 */
final class VueBareHandlerArgument
{
    /** Native DOM events: the argument is always the Event. */
    private const EVENTS = 'click|dblclick|contextmenu|auxclick|mousedown|mouseup|pointerdown|pointerup|keydown|keyup|keypress|input|change|submit|focus|blur|focusin|focusout';

    /** Kit components whose click is the native one. */
    private const KIT_NATIVE = ['Button', 'Link'];

    /** @return list<array{target: string, check: string}> */
    public function violations(Configuration $config): array
    {
        $violations = [];
        foreach ((new FrontendSource($config))->appFiles() as $path => $source) {
            if (! str_ends_with($path, '.vue')) {
                continue;
            }
            $script = FrontendSource::script($path, $source);
            $kit = array_values(array_intersect(UiNewTab::kitComponents($script), self::KIT_NATIVE));
            foreach (self::bareHandlers(FrontendSource::template($source)) as [$tag, $event, $handler]) {
                $native = preg_match('~^[a-z][a-z0-9]*$~', $tag) === 1;
                if (! $native && ! ($event === 'click' && in_array($tag, $kit, true))) {
                    continue;
                }
                $first = self::firstParameter($script, $handler);
                if ($first !== null && ! self::takesEvent($first)) {
                    $violations[] = ['target' => $path, 'check' => "@{$event}=\"{$handler}\" on <{$tag}> passes the event as {$handler}'s first argument ({$first}): call it, @{$event}=\"{$handler}()\""];
                }
            }
        }

        return array_values(array_unique($violations, SORT_REGULAR));
    }

    /** @return list<array{0: string, 1: string, 2: string}> tag, event and handler name for each `@event="name"` or `v-on:event="name"` */
    private static function bareHandlers(string $template): array
    {
        preg_match_all('~<([A-Za-z][\w.-]*)\b((?:"[^"]*"|\'[^\']*\'|[^\'">])*)>~s', $template, $tags, PREG_SET_ORDER);
        $found = [];
        foreach ($tags as $tag) {
            preg_match_all('~(?:^|\s)(?:@|v-on:)('.self::EVENTS.')(?:\.[\w-]+)*\s*=\s*([\'"])\s*([A-Za-z_$][\w$]*)\s*\2~', $tag[2], $handlers, PREG_SET_ORDER);
            foreach ($handlers as $handler) {
                $found[] = [$tag[1], $handler[1], $handler[3]];
            }
        }

        return $found;
    }

    /** The handler's first declared parameter, as written, or null when it takes none or is not declared in this file. */
    private static function firstParameter(string $script, string $name): ?string
    {
        $quoted = preg_quote($name, '~');
        $patterns = [
            '~\bfunction\s+'.$quoted.'\s*(?:<[^>]*>)?\s*\(([^)]*)\)~',
            '~\b(?:const|let|var)\s+'.$quoted.'\s*(?::[^=]+)?=\s*(?:async\s+)?(?:function\s*)?\(([^)]*)\)~',
            '~\b(?:const|let|var)\s+'.$quoted.'\s*=\s*(?:async\s+)?([A-Za-z_$][\w$]*)\s*=>~',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $script, $match)) {
                $parameters = trim($match[1]);
                if ($parameters === '') {
                    return null;
                }

                return trim(self::splitTopLevel($parameters)[0]);
            }
        }

        return null;
    }

    private static function takesEvent(string $parameter): bool
    {
        if (preg_match('~^[\w$]+\??\s*:\s*(.+)$~s', $parameter, $typed)) {
            return preg_match('~^(?:\w*Event|Event)(?:\s*\|\s*undefined)?$~', trim($typed[1])) === 1;
        }

        return preg_match('~^(?:_?e|_?event|_?evt|_)$~', $parameter) === 1;
    }

    /** @return list<string> */
    private static function splitTopLevel(string $parameters): array
    {
        $parts = [];
        $depth = 0;
        $current = '';
        foreach (str_split($parameters) as $char) {
            if (in_array($char, ['(', '{', '[', '<'], true)) {
                $depth++;
            } elseif (in_array($char, [')', '}', ']', '>'], true)) {
                $depth--;
            }
            if ($char === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';

                continue;
            }
            $current .= $char;
        }
        $parts[] = $current;

        return $parts;
    }
}
