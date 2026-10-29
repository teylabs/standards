<?php

declare(strict_types=1);

namespace Tey\Standards\Adoption;

/**
 * The closed lists `standards:adoption` uses for direction 2 (gaps), in one versioned place. Bump VERSION whenever a list changes: the
 * report carries it, so two reports with different list versions are not compared as like for like.
 */
final class Lists
{
    public const VERSION = 2;

    /**
     * App component file names (without `.vue`) that are generic UI building blocks. A trailing `*` is a prefix match; anything else is
     * exact, so a domain-prefixed name such as `InvoiceTable` is not generic (it is a gap candidate only when it imports a UI library).
     */
    public const GENERIC_NAMES = [
        'Button', 'Input*', 'Select*', 'Modal', 'Dialog', 'Dropdown*', 'Table', 'DataTable', 'Tabs', 'Toast', 'Tooltip', 'Badge',
        'Card', 'Avatar', 'Pagination', 'DatePicker', 'Combobox', 'Menu*',
    ];

    /** Framework runtimes: never a third-party UI library. A trailing `/*` matches the whole scope. */
    public const FRAMEWORK_RUNTIMES = ['vue', '@inertiajs/*', '@vueuse/*'];

    /**
     * package.json dependencies that are build, bundler, compiler, type, lint, format or test tooling, not UI/JS libraries an app renders
     * with. Never gap candidates. A trailing `*` is a prefix match and a leading `*` a suffix match (`*-loader` is any webpack loader).
     * Version 2 adds the Laravel Mix / webpack era (laravel-mix, loaders, webpack, Babel, the Vue 2 and 3 SFC compilers, vue-eslint-parser,
     * globals, cross-env).
     */
    public const TOOLING = [
        'vite', '@vitejs/*', 'laravel-vite-plugin', 'laravel-mix', 'laravel-mix-*', '*-loader', 'webpack*', '@babel/*', '@vue/compiler-sfc',
        'vue-template-compiler', 'vue-eslint-parser', 'globals', 'cross-env', 'typescript', 'vue-tsc', '@types/*', '@vue/tsconfig', 'eslint*',
        '@eslint/*', 'typescript-eslint', '@typescript-eslint/*', 'prettier*', '@prettier/*', 'tailwindcss', '@tailwindcss/*', 'postcss*',
        'autoprefixer', 'sass', 'sass-embedded', 'concurrently', 'vitest', '@vitest/*', '@vue/test-utils', 'jsdom', 'happy-dom',
        '@playwright/test', 'playwright', 'husky', 'lint-staged', '@laravel/vite-plugin-wayfinder',
    ];

    public static function generic(string $name): bool
    {
        return self::matchesAny($name, self::GENERIC_NAMES);
    }

    public static function runtime(string $package): bool
    {
        return self::matchesAny($package, self::FRAMEWORK_RUNTIMES);
    }

    public static function tooling(string $package): bool
    {
        return self::matchesAny($package, self::TOOLING);
    }

    /** @param list<string> $patterns */
    private static function matchesAny(string $value, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $matches = match (true) {
                str_ends_with($pattern, '*') => str_starts_with($value, substr($pattern, 0, -1)),
                str_starts_with($pattern, '*') => str_ends_with($value, substr($pattern, 1)),
                default => $value === $pattern,
            };
            if ($matches) {
                return true;
            }
        }

        return false;
    }
}
