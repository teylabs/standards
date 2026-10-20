<?php

declare(strict_types=1);

namespace Tey\Standards;

use InvalidArgumentException;

final class PageConventions
{
    /** @return list<string> */
    public static function scaffolds(string $base): array
    {
        $contents = file_get_contents($base.'/composer.json');

        if ($contents === false) {
            throw new InvalidArgumentException('Cannot read page scaffold manifest.');
        }

        $settings = json_decode($contents, flags: JSON_THROW_ON_ERROR);

        foreach (['extra', 'tey-standards'] as $key) {
            if (! is_object($settings)) {
                throw new InvalidArgumentException('Page scaffold metadata must be an object.');
            }

            $settings = property_exists($settings, $key) ? $settings->{$key} : (object) [];
        }

        if (! is_object($settings)) {
            throw new InvalidArgumentException('Page scaffold metadata must be an object.');
        }

        $names = property_exists($settings, 'pageScaffolds') ? $settings->pageScaffolds : [];

        if (! is_array($names) || ! array_is_list($names)) {
            throw new InvalidArgumentException('pageScaffolds must be a list of directory names.');
        }

        foreach ($names as $name) {
            if (! is_string($name) || ! preg_match('/^[A-Za-z][A-Za-z0-9]*$/D', $name)) {
                throw new InvalidArgumentException('pageScaffolds must be a list of directory names.');
            }
        }

        return $names;
    }

    /** @param list<string> $scaffolds */
    public static function validPath(string $path, array $scaffolds): bool
    {
        $parts = explode('/', $path);
        $component = pathinfo(array_pop($parts), PATHINFO_FILENAME);

        foreach ($parts as $part) {
            if (in_array(strtolower($part), ['pages', 'components', 'layouts'], true) && $part !== strtolower($part)) {
                return false;
            }

            if (! in_array($part, $scaffolds, true) && ! preg_match('/^[A-Z][A-Za-z0-9]*$/D', $part)) {
                return false;
            }
        }

        return (bool) preg_match('/^[A-Z][A-Za-z0-9]*$/D', $component);
    }
}
