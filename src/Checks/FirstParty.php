<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use Tey\Standards\Configuration;
use Tey\Standards\Source;

/**
 * Rule `packages.first-party`: where a Laravel first-party package does the job, the app uses it, not a third-party package or its own code.
 *
 * - A package in composer.json `require` (not require-dev) that has a first-party replacement is a finding on composer.json. stripe/stripe-php
 *   counts only without laravel/cashier, which requires it itself and hands it out as Cashier::stripe().
 * - A string in the source roots naming an OAuth token endpoint Socialite covers (GitHub's /login/oauth/access_token, Google's
 *   oauth2.googleapis.com/token) is a hand-written code exchange or token refresh: a finding on that file. Comments don't count.
 *
 * Exceptions are approved here, with their reason, and an app cites one per package in Configuration::$firstPartyExceptions
 * (`['stripe/stripe-php' => 'stripe-connect']`). A citation of an unknown exception, of an exception that doesn't cover the package, or for a
 * package the rule doesn't flag is itself a finding. A gap still being fixed goes in the allowlist, with an expiry, like any finding.
 */
final readonly class FirstParty
{
    public const RULE = 'packages.first-party';

    /** Third-party package => the first-party package that replaces it. */
    public const REPLACEMENTS = [
        'stripe/stripe-php' => 'laravel/cashier',
        'spatie/laravel-stripe-webhooks' => 'laravel/cashier',
        'tightenco/ziggy' => 'laravel/wayfinder',
        'anthropic-ai/sdk' => 'laravel/ai',
        'openai-php/client' => 'laravel/ai',
        'openai-php/laravel' => 'laravel/ai',
        'beyondcode/laravel-websockets' => 'laravel/reverb',
        'tymon/jwt-auth' => 'laravel/sanctum or laravel/passport',
        'league/oauth2-client' => 'laravel/socialite',
        'league/oauth2-github' => 'laravel/socialite',
        'league/oauth2-google' => 'laravel/socialite',
        'ylsideas/feature-flags' => 'laravel/pennant',
    ];

    /** Approved exceptions: id => the packages it covers and why no first-party package does the job. Adding one is a standards change. */
    public const EXCEPTIONS = [
        'stripe-connect' => [
            'packages' => ['stripe/stripe-php', 'spatie/laravel-stripe-webhooks'],
            'reason' => 'Stripe Connect: payments and webhooks on each customer\'s connected account. Cashier bills only the platform\'s own account (Jasper\'s ruling, Nov 8 2026).',
        ],
    ];

    private const OAUTH = '~/login/oauth/access_token|oauth2\.googleapis\.com/token~';

    public function __construct(private Configuration $config, private Source $source) {}

    /** @return list<array{target: string, check: string, hint: string}> */
    public function violations(): array
    {
        $composer = is_file($this->config->base.'/composer.json') ? json_decode((string) file_get_contents($this->config->base.'/composer.json'), true) : null;
        $required = is_array($composer['require'] ?? null) ? $composer['require'] : [];
        $flagged = array_values(array_filter(array_keys(self::REPLACEMENTS), fn (string $package): bool => isset($required[$package])
            && ! ($package === 'stripe/stripe-php' && isset($required['laravel/cashier']))));

        $violations = [];
        foreach ($this->config->firstPartyExceptions as $package => $id) {
            $exception = self::EXCEPTIONS[$id] ?? null;
            $problem = match (true) {
                $exception === null => "cites unknown exception [{$id}] for {$package}; approved: ".implode(', ', array_keys(self::EXCEPTIONS)),
                ! in_array($package, $exception['packages'], true) => "exception [{$id}] does not cover {$package}",
                ! in_array($package, $flagged, true) => "stale exception [{$id}] for {$package}: the rule doesn't flag it, delete it",
                default => null,
            };
            if ($problem !== null) {
                $violations[] = ['target' => 'firstPartyExceptions', 'check' => $problem, 'hint' => 'Cite an approved exception from Checks\FirstParty::EXCEPTIONS, or propose a new one to tey/standards.'];
            }
        }

        foreach ($flagged as $package) {
            if (in_array($package, self::EXCEPTIONS[$this->config->firstPartyExceptions[$package] ?? '']['packages'] ?? [], true)) {
                continue;
            }
            $violations[] = ['target' => 'composer.json', 'check' => 'requires '.$package.'; use '.self::REPLACEMENTS[$package],
                'hint' => 'Move to '.self::REPLACEMENTS[$package].'. If no first-party package can do the job, cite an approved exception in Configuration::$firstPartyExceptions.'];
        }

        foreach ($this->source->files($this->config->sourceRoots) as $path => $text) {
            if (preg_match(self::OAUTH, $text) !== 1) {
                continue;
            }
            foreach (token_get_all($text) as $token) {
                if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true) && preg_match(self::OAUTH, $token[1], $match) === 1) {
                    $violations[] = ['target' => $path, 'check' => 'hand-written OAuth token exchange ('.$match[0].'); use laravel/socialite', 'hint' => 'Use a Socialite driver (github, google): user() for the code exchange, refreshToken() for a refresh.'];
                    break;
                }
            }
        }

        return $violations;
    }
}
