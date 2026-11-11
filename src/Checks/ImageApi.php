<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use Tey\Standards\Configuration;
use Tey\Standards\Source;

/**
 * Rule `media.image-api`: userland image code goes through Laravel's first-party image API; Media Library keeps spatie/image. A raw `new Imagick`,
 * a GD `imagecreate*()` call, or direct use of Intervention's `ImageManager` (`new ImageManager`, `ImageManager::…`, a fully qualified name) is
 * flagged. Allowed: vendor (never scanned), Media Library code (a path with a `MediaLibrary` or `media-library` segment, or a file that imports
 * `Spatie\MediaLibrary`), and a line that reads pixels, marked by a `@raw-image <reason>` comment on the same line or one of the two lines above.
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
            if (preg_match('~Imagick|imagecreate|ImageManager~i', $text) !== 1 || $this->mediaLibrary($path, $text)) {
                continue;
            }
            $all = token_get_all($text);
            $marked = $this->markedLines($all);
            $tokens = array_values(array_filter($all, fn (mixed $token): bool => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
            $intervention = preg_match('~^\s*use\s+\\\\?Intervention\\\\Image\\\\ImageManager\b~mi', $text) === 1;
            foreach ($tokens as $i => $token) {
                $finding = $this->finding($tokens, $i, $intervention);
                if ($finding !== null && ! isset($marked[$token[2]])) {
                    $violations[] = ['target' => $path, 'check' => $finding.' outside Media Library, with no @raw-image reason', 'line' => $token[2], 'hint' => self::HINT];
                }
            }
        }

        return $violations;
    }

    /** What the token at $i does with a raw image library, or null. */
    private function finding(array $tokens, int $i, bool $intervention): ?string
    {
        $token = $tokens[$i];
        if (! is_array($token) || ! in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            return null;
        }
        $name = ltrim($token[1], '\\');
        $previous = $tokens[$i - 1] ?? null;
        $new = is_array($previous) && $previous[0] === T_NEW;

        if ($new && strcasecmp($name, 'Imagick') === 0) {
            return 'a raw `new Imagick`';
        }
        if (! str_contains($name, '\\') && stripos($name, 'imagecreate') === 0 && ($tokens[$i + 1] ?? null) === '('
            && ! (is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true))) {
            return 'a GD `'.$name.'()` call';
        }
        $manager = strcasecmp($name, 'Intervention\\Image\\ImageManager') === 0 || ($intervention && strcasecmp($name, 'ImageManager') === 0);
        $used = $new || (is_array($tokens[$i + 1] ?? null) && $tokens[$i + 1][0] === T_DOUBLE_COLON);
        if ($manager && $used && ! (is_array($previous) && $previous[0] === T_USE)) {
            return 'direct use of Intervention\'s ImageManager';
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

    /** Media Library code keeps spatie/image and whatever driver it wraps. */
    private function mediaLibrary(string $path, string $text): bool
    {
        return preg_match('~(?:^|/)(?:MediaLibrary|media-library)(?:/|$)~i', dirname($path)) === 1
            || preg_match('~^\s*use\s+\\\\?Spatie\\\\MediaLibrary\\\\~mi', $text) === 1;
    }
}
