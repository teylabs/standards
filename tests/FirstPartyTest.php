<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Tey\Standards\Conformance;
use Tey\Standards\Enforcement\Gate;

/** Rule packages.first-party on a scratch app with the given composer.json and src/ files; tenantConfiguration() comes from TenantAwareTest. */
function firstPartyFindings(array $composer, array $files = [], array $exceptions = []): array
{
    $base = tenantApp($files);
    file_put_contents($base.'/composer.json', json_encode($composer));
    try {
        return array_map(fn (array $v): string => $v['target'].' '.$v['check'], (new Conformance(tenantConfiguration($base, ['firstPartyExceptions' => $exceptions])))->violations('packages.first-party'));
    } finally {
        (new Filesystem)->deleteDirectory(dirname($base));
    }
}

test('packages.first-party flags required packages a first-party package replaces, stripe-php only without Cashier, and never require-dev', function () {
    expect(firstPartyFindings(['require' => ['stripe/stripe-php' => '^17', 'tightenco/ziggy' => '^2', 'laravel/ai' => '^0.1'], 'require-dev' => ['anthropic-ai/sdk' => '^1']]))->toBe([
        'composer.json requires stripe/stripe-php; use laravel/cashier',
        'composer.json requires tightenco/ziggy; use laravel/wayfinder',
    ])->and(firstPartyFindings(['require' => ['stripe/stripe-php' => '^17', 'laravel/cashier' => '^16']]))->toBe([])
        ->and(Gate::ADVISORY)->not->toContain('packages.first-party');
});

test('packages.first-party accepts only an approved exception that covers the package and is still needed', function () {
    $composer = ['require' => ['stripe/stripe-php' => '^17', 'spatie/laravel-stripe-webhooks' => '^3', 'tightenco/ziggy' => '^2']];

    expect(firstPartyFindings($composer, exceptions: ['stripe/stripe-php' => 'stripe-connect', 'spatie/laravel-stripe-webhooks' => 'stripe-connect', 'tightenco/ziggy' => 'stripe-connect', 'league/oauth2-github' => 'stripe-connect', 'openai-php/client' => 'we-like-it']))->toBe([
        'firstPartyExceptions exception [stripe-connect] does not cover tightenco/ziggy',
        'firstPartyExceptions exception [stripe-connect] does not cover league/oauth2-github',
        'firstPartyExceptions cites unknown exception [we-like-it] for openai-php/client; approved: stripe-connect',
        'composer.json requires tightenco/ziggy; use laravel/wayfinder',
    ])->and(firstPartyFindings(['require' => ['stripe/stripe-php' => '^17', 'laravel/cashier' => '^16']], exceptions: ['stripe/stripe-php' => 'stripe-connect']))->toBe([
        "firstPartyExceptions stale exception [stripe-connect] for stripe/stripe-php: the rule doesn't flag it, delete it",
    ]);
});

test('packages.first-party flags a hand-written OAuth token exchange once per file, not a comment', function () {
    expect(firstPartyFindings(['require' => []], [
        'GitHubIdentity.php' => <<<'PHP'
            final class GitHubIdentity
            {
                // Was: Http::post('https://oauth2.googleapis.com/token')
                public function exchange(string $code): array
                {
                    $first = Http::asForm()->post($this->webUrl.'/login/oauth/access_token', ['code' => $code]);

                    return Http::asForm()->post("{$this->webUrl}/login/oauth/access_token", ['code' => $code])->json();
                }
            }
            PHP,
        'Notes.php' => "// POST https://oauth2.googleapis.com/token by hand\nfinal class Notes {}\n",
    ]))->toBe(['src/GitHubIdentity.php hand-written OAuth token exchange (/login/oauth/access_token); use laravel/socialite']);
});

test('packages.first-party fails the Pest run on a finding, and an allowlist entry with an expiry holds a known gap', function () {
    $base = tenantApp([]);
    file_put_contents($base.'/composer.json', json_encode(['require' => ['tightenco/ziggy' => '^2']]));
    $entry = ['rule' => 'packages.first-party', 'target' => 'composer.json', 'check' => 'requires tightenco/ziggy; use laravel/wayfinder', 'reason' => 'Move to Wayfinder: teylabs/macrosheets#1', 'date' => '2026-11-22', 'expires' => '2027-02-28'];
    try {
        expect(fn () => (new Conformance(tenantConfiguration($base)))->assert('packages.first-party'))->toThrow(Exception::class, 'violation: composer.json | requires tightenco/ziggy');
        (new Conformance(tenantConfiguration($base, ['allowlist' => [$entry]])))->assert('packages.first-party');
    } finally {
        (new Filesystem)->deleteDirectory(dirname($base));
    }
});
