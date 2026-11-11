<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use Tey\Standards\Configuration;
use Tey\Standards\Source;

/**
 * Rule `media.image-api`: userland image code goes through Laravel's first-party image API; Media Library keeps spatie/image. A raw `new Imagick`,
 * a GD `imagecreate*()` call, or direct use of Intervention's `ImageManager` (`new ImageManager`, `ImageManager::…`, a fully qualified name) is
 * flagged. Allowed: vendor (never scanned), Media Library code (a path with a `MediaLibrary` or `media-library` segment, or a file that imports
 * `Spatie\MediaLibrary`), a custom driver for Laravel's image API (a file that implements
 * `Illuminate\Contracts\Image\Driver` or registers one with `Image::extend()`), and a raw Imagick or GD line that reads pixels (never an ImageManager), marked by a `@raw-image <reason>` comment on the same line or one of the two lines above.
 *
 * Lexical: a mention in a comment or a string doesn't count. Advisory until an app lists the rule in Configuration::$optionalRules.
 */
final readonly class ImageApi
{
    public const RULE = 'media.image-api';

    public const MARKER = '@raw-image';

    /** How many lines above a call its marker comment may sit. */
    private const REACH = 2;

    private const HINT = 'Use Laravel\'s first-party image API; Media Library conversions keep spatie/image. Raw Imagick or GD is only for reading pixels: say why in a `// @raw-image <reason>` comment on the line or just above it.';

    public function __construct(private Configuration $config, private Source $source) {}

    /** @return list<array{target: string, check: string, line: int, hint: string}> */
    public function violations(): array
    {
        $violations = [];
        foreach ($this->source->files($this->config->sourceRoots) as $path => $text) {
            if (preg_match('~Imagick|imagecreate|ImageManager~i', $text) !== 1) {
                continue;
            }
            $all = token_get_all($text);
            $marked = $this->markedLines($all);
            $tokens = array_values(array_filter($all, fn (mixed $token): bool => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
            $imports = $this->imports($tokens);
            if ($this->mediaLibrary($path, $imports)) {
                continue;
            }
            if ($this->customDriver($tokens, $imports)) {
                continue;
            }
            foreach ($tokens as $i => $token) {
                $finding = $this->finding($tokens, $i, $imports);
                // The marker excuses only raw Imagick or GD (a pixel read); Intervention's ImageManager never reads pixels the image API can't.
                if ($finding !== null && ! ($finding[1] && isset($marked[$token[2]]))) {
                    $violations[] = ['target' => $path, 'check' => $finding[0].' outside Media Library'.($finding[1] ? ', with no @raw-image reason' : ''), 'line' => $token[2], 'hint' => self::HINT];
                }
            }
        }

        return $violations;
    }

    /**
     * What the token at $i does with a raw image library, and whether a @raw-image marker may excuse it; null when nothing.
     *
     * @param  array<string, string>  $imports
     * @return array{0: string, 1: bool}|null
     */
    private function finding(array $tokens, int $i, array $imports): ?array
    {
        $token = $tokens[$i];
        if (! is_array($token) || ! in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            return null;
        }
        $name = ltrim($token[1], '\\');
        $previous = $tokens[$i - 1] ?? null;
        $new = is_array($previous) && $previous[0] === T_NEW;

        if ($new && strcasecmp($name, 'Imagick') === 0) {
            return ['a raw `new Imagick`', true];
        }
        if (! str_contains($name, '\\') && stripos($name, 'imagecreate') === 0 && ($tokens[$i + 1] ?? null) === '('
            && ! (is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true))) {
            return ['a GD `'.$name.'()` call', true];
        }
        $manager = strcasecmp($this->resolve($token, $imports), 'Intervention\\Image\\ImageManager') === 0;
        $used = $new || (is_array($tokens[$i + 1] ?? null) && $tokens[$i + 1][0] === T_DOUBLE_COLON);
        if ($manager && $used && ! (is_array($previous) && $previous[0] === T_USE)) {
            return ['direct use of Intervention\'s ImageManager', false];
        }

        return null;
    }

    /** Lines a `@raw-image <reason>` comment covers: its own last line and the REACH lines below it. */
    private function markedLines(array $tokens): array
    {
        $lines = [];
        foreach ($tokens as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
                && preg_match('~(?:^|[\s/*#])'.preg_quote(self::MARKER, '~').':?[ \t]+[^\s*]~', $token[1]) === 1) {
                $end = $token[2] + substr_count(rtrim($token[1], "\n"), "\n");
                for ($line = $token[2]; $line <= $end + self::REACH; $line++) {
                    $lines[$line] = true;
                }
            }
        }

        return $lines;
    }

    /**
     * A custom driver for Laravel's image API needs the raw library: a file that implements Illuminate\Contracts\Image\Driver, or registers a
     * driver with Image::extend(), is allowed.
     */
    private function customDriver(array $tokens, array $imports): bool
    {
        $implements = false;
        foreach ($tokens as $i => $token) {
            if (is_array($token) && $token[0] === T_IMPLEMENTS) {
                $implements = true;
            } elseif ($token === '{') {
                $implements = false;
            } elseif ($implements && is_array($token)) {
                if (strcasecmp($this->resolve($token, $imports), 'Illuminate\\Contracts\\Image\\Driver') === 0) {
                    return true;
                }
            }
            if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                && preg_match('~(?:^|\\\\)Image$~', $token[1]) === 1 && ($tokens[$i + 1][0] ?? null) === T_DOUBLE_COLON
                && strcasecmp($tokens[$i + 2][1] ?? '', 'extend') === 0) {
                return true;
            }
        }

        return false;
    }

    /** Media Library code keeps spatie/image and whatever driver it wraps. */
    private function mediaLibrary(string $path, array $imports): bool
    {
        if (preg_match('~(?:^|/)(?:MediaLibrary|media-library)(?:/|$)~i', dirname($path)) === 1) {
            return true;
        }
        foreach ($imports as $name) {
            if (stripos($name, 'Spatie\\MediaLibrary\\') === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The file's class imports, read from its tokens (comments and strings never count): lowercased alias => fully qualified name. Handles
     * aliases and grouped imports; `use function`/`use const`, a trait `use` inside a class and a closure's `use (…)` are skipped.
     *
     * @return array<string, string>
     */
    private function imports(array $tokens): array
    {
        $imports = [];
        $depth = 0;
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif ($token === '}') {
                $depth--;
            }
            if (! is_array($token) || $token[0] !== T_USE || $depth > 0 || ($tokens[$i + 1] ?? null) === '('
                || (is_array($tokens[$i + 1] ?? null) && in_array($tokens[$i + 1][0], [T_FUNCTION, T_CONST], true))) {
                continue;
            }
            $prefix = '';
            $name = '';
            $alias = null;
            $grouped = false;
            for ($i++; $i < $count && $tokens[$i] !== ';'; $i++) {
                $part = $tokens[$i];
                if ($part === '{') {
                    [$prefix, $name, $grouped] = [rtrim($name, '\\').'\\', '', true];
                } elseif ($part === ',' || $part === '}') {
                    $this->import($imports, $prefix.$name, $alias);
                    [$name, $alias] = ['', null];
                } elseif (is_array($part) && $part[0] === T_AS) {
                    $alias = is_array($tokens[$i + 1] ?? null) ? $tokens[++$i][1] : null;
                } elseif (is_array($part)) {
                    $name .= $part[1];
                }
            }
            if (! $grouped) {
                $this->import($imports, $name, $alias);
            }
        }

        return $imports;
    }

    private function import(array &$imports, string $name, ?string $alias): void
    {
        $name = trim($name, '\\');
        if ($name !== '' && ! str_ends_with($name, '\\')) {
            $imports[strtolower($alias ?? substr((string) strrchr('\\'.$name, '\\'), 1))] = $name;
        }
    }

    /** The fully qualified name a class-name token stands for, by the file's imports. */
    private function resolve(array $token, array $imports): string
    {
        if ($token[0] === T_NAME_FULLY_QUALIFIED) {
            return ltrim($token[1], '\\');
        }
        $parts = explode('\\', $token[1], 2);

        return isset($imports[strtolower($parts[0])]) ? $imports[strtolower($parts[0])].(isset($parts[1]) ? '\\'.$parts[1] : '') : $token[1];
    }
}
