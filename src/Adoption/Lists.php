<?php

declare(strict_types=1);

namespace Tey\Standards\Adoption;

/**
 * The closed lists `standards:adoption` uses for direction 2 (gaps), in one versioned place. Bump VERSION whenever a list changes: the
 * report carries it, so two reports with different list versions are not compared as like for like.
 */
final class Lists
{
    public const VERSION = 4;

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
        // Version 4.
        'vite-plus',
    ];

    /**
     * package.json dependencies that are not UI: HTTP clients, id generators, query strings, cookies, crypto, realtime transports,
     * routing helpers, general utilities (lodash), the framework (Inertia, Vue, its router and stores) and validation. Never gap candidates. Same matching as TOOLING.
     * Version 3 adds this list. Date libraries (dayjs, date-fns, moment, luxon) also stay gaps, because a kit surface may cover date display.
     */
    public const NON_UI = [
        'axios', 'ky', 'ofetch', 'uuid', 'nanoid', 'qs', 'query-string', 'crypto-js', 'js-cookie', 'pusher-js', 'laravel-echo',
        'socket.io-client', 'ziggy-js', '@inertiajs/*', 'vue', 'vue-router', 'pinia', 'vuex', '@vueuse/core', 'mitt', 'zod', 'yup', 'lodash',
        'lodash-es',
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

    public static function nonUi(string $package): bool
    {
        return self::matchesAny($package, self::NON_UI);
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
