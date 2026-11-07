<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

/**
 * A small element tree of a Vue SFC's `<template>`, for the layout rules (ui.action-group, ui.row-actions, ui.page-title-dup, ui.no-outer-margins,
 * ui.page-grid, ui.primitive-pointers) that ask about parents and children rather than single tags. Lexical: no expression is evaluated, tag names
 * are read as written (kebab-case components read as PascalCase), and a malformed template yields what it can. Each node keeps its source line.
 */
final class VueTemplate
{
    /** HTML elements that never have a closing tag. */
    private const VOID = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'];

    /** @param list<self|string> $children elements, and trimmed non-empty text */
    private function __construct(
        public readonly string $tag,
        public readonly string $attributes,
        public readonly int $line,
        public array $children = [],
        public ?self $parent = null,
    ) {}

    /** The root `<template>` of an SFC (or of a bare template string); null when the file has none. */
    public static function parse(string $source): ?self
    {
        // Blank out comments, scripts and styles with their own newlines, so lines stay true.
        $blank = fn (array $match): string => str_repeat("\n", substr_count($match[0], "\n"));
        $source = preg_replace_callback('~<!--.*?-->|<(script|style)\b[^>]*>.*?</\1\s*>~si', $blank, $source) ?? '';

        if (preg_match('~<template\b~', $source, $open, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $root = new self('#root', '', 1);
        $stack = [$root];
        $offset = $open[0][1];
        $pattern = '~<(/?)([A-Za-z][\w.:-]*)((?:"[^"]*"|\'[^\']*\'|[^\'">])*)>~s';

        while (preg_match($pattern, $source, $tag, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $text = trim(substr($source, $offset, $tag[0][1] - $offset));
            $current = end($stack);
            if ($text !== '' && count($stack) > 1) {
                $current->children[] = $text;
            }
            $line = substr_count($source, "\n", 0, $tag[0][1]) + 1;
            $offset = $tag[0][1] + strlen($tag[0][0]);
            $name = self::name($tag[2][0]);

            if ($tag[1][0] === '/') {
                for ($index = count($stack) - 1; $index > 0; $index--) {
                    if ($stack[$index]->tag === $name) {
                        $stack = array_slice($stack, 0, $index);
                        break;
                    }
                }
                if (count($stack) === 1) {
                    break;
                }

                continue;
            }

            $attributes = $tag[3][0];
            $node = new self($name, $attributes, $line, [], $current);
            $current->children[] = $node;
            if (! str_ends_with(rtrim($attributes), '/') && ! in_array($name, self::VOID, true)) {
                $stack[] = $node;
            }
        }

        foreach ($root->children as $child) {
            if ($child instanceof self && $child->tag === 'template') {
                return $child;
            }
        }

        return null;
    }

    /** `button-group` reads as `ButtonGroup`; a native or already PascalCase tag is kept. */
    private static function name(string $tag): string
    {
        return str_contains($tag, '-') && strtolower($tag) === $tag ? str_replace(' ', '', ucwords(str_replace('-', ' ', $tag))) : $tag;
    }

    public function native(): bool
    {
        return preg_match('~^[a-z][a-z0-9]*$~', $this->tag) === 1;
    }

    /** @return list<self> */
    public function elements(): array
    {
        return array_values(array_filter($this->children, fn (self|string $child): bool => $child instanceof self));
    }

    /** @return list<self> every element under this one, depth first */
    public function descendants(): array
    {
        $found = [];
        foreach ($this->elements() as $child) {
            $found[] = $child;
            array_push($found, ...$child->descendants());
        }

        return $found;
    }

    public function has(string $name): bool
    {
        return preg_match('~(?:^|\s)'.preg_quote($name, '~').'(?=[\s=/]|$)~', $this->attributes) === 1;
    }

    /** A static attribute's value; null when absent or only bound. */
    public function attribute(string $name): ?string
    {
        return preg_match('~(?:^|\s)'.preg_quote($name, '~').'\s*=\s*([\'"])(.*?)\1~s', $this->attributes, $match) === 1 ? $match[2] : null;
    }

    /** A bound attribute's expression (`:name` or `v-bind:name`); null when absent. */
    public function bound(string $name): ?string
    {
        return preg_match('~(?:^|\s)(?::|v-bind:)'.preg_quote($name, '~').'\s*=\s*([\'"])(.*?)\1~s', $this->attributes, $match) === 1 ? $match[2] : null;
    }

    /** The slot a `<template #name>` / `v-slot:name` fills, or null for any other node. */
    public function slot(): ?string
    {
        if ($this->tag !== 'template') {
            return null;
        }
        if (preg_match('~(?:^|\s)(?:#|v-slot:)(\[[^\]]*\]|[\w-]+)~', $this->attributes, $match) === 1) {
            return $match[1];
        }

        return preg_match('~(?:^|\s)v-slot(?=[\s=>]|$)~', $this->attributes) === 1 ? 'default' : null;
    }

    /**
     * The class tokens the template states: the static `class`, and the quoted strings of a `:class` binding (both branches of a ternary, an
     * object's keys, an array's strings). A computed binding contributes nothing.
     *
     * @return list<string>
     */
    public function classes(): array
    {
        $text = (string) $this->attribute('class');
        if (($bound = $this->bound('class')) !== null && preg_match_all('~\'([^\']*)\'|`([^`$]*)`~', $bound, $strings) > 0) {
            $text .= ' '.implode(' ', [...$strings[1], ...$strings[2]]);
        }

        return array_values(array_filter(preg_split('~\s+~', trim($text)) ?: [], fn (string $token): bool => $token !== ''));
    }

    /** A class token without its variants (`md:`, `hover:`) or `!` important mark. */
    public static function utility(string $token): string
    {
        $parts = explode(':', $token);

        return trim((string) end($parts), '!');
    }

    /** @param list<string> $utilities */
    public function hasUtility(string $pattern): bool
    {
        foreach ($this->classes() as $token) {
            if (preg_match($pattern, self::utility($token)) === 1) {
                return true;
            }
        }

        return false;
    }

    /** The text inside this element, with markup and mustaches removed. */
    public function text(): string
    {
        $text = [];
        foreach ($this->children as $child) {
            $text[] = $child instanceof self ? $child->text() : $child;
        }

        return trim(preg_replace('~\s+~', ' ', implode(' ', $text)) ?? '');
    }

    /** In a v-if / v-else-if / v-else chain, after the first branch: an alternative to its previous sibling, never shown with it. */
    public function alternative(): bool
    {
        return preg_match('~(?:^|\s)v-else(?:-if)?(?=[\s=]|$)~', $this->attributes) === 1;
    }

    /**
     * The nodes a component renders in its default slot, as its direct children: `<template #default>` and plain wrapper `<template v-if/v-for>`
     * are looked through, named slots are left out.
     *
     * @return list<self|string>
     */
    public function flow(): array
    {
        $flow = [];
        foreach ($this->children as $child) {
            if ($child instanceof self && $child->tag === 'template') {
                $slot = $child->slot();
                if ($slot === null || $slot === 'default') {
                    array_push($flow, ...$child->flow());
                }

                continue;
            }
            $flow[] = $child;
        }

        return $flow;
    }

    /** The nearest ancestor (or self) whose tag is one of $tags. */
    public function closest(array $tags): ?self
    {
        for ($node = $this; $node !== null; $node = $node->parent) {
            if (in_array($node->tag, $tags, true)) {
                return $node;
            }
        }

        return null;
    }

    /** The slot template this node sits in, nearest first, below $stop. */
    public function inSlot(string $pattern, ?self $stop = null): bool
    {
        for ($node = $this; $node !== null && $node !== $stop; $node = $node->parent) {
            if (($slot = $node->slot()) !== null && preg_match($pattern, $slot) === 1) {
                return true;
            }
        }

        return false;
    }
}
