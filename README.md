# Standards: Deployment Preflight and Architecture Checks for Laravel

`tey/standards` checks deployment configuration before migrate and checks configured PHP boundaries, placement and controller rules with named Pest tests. Each architecture result carries a conformance rule number. A dated ratchet permits existing failures and rejects stale exceptions.

**Status: trial.** Requires PHP 8.4.1+ and Laravel 13 components. Architecture checks additionally require Pest 4.7+, its architecture plugin 4.x and PHP-Parser 5.x. Pest 5 is not supported.

## Install

Install as a production dependency so deployment preflight remains available after `composer install --no-dev`:

```sh
composer require tey/standards
```

Laravel discovers the service provider automatically. The production install does not require Pest or PHP-Parser. For architecture checks, install the optional tools in the application's `require-dev`:

```sh
composer require --dev 'pestphp/pest:^4.7' 'pestphp/pest-plugin-arch:^4.0' 'nikic/php-parser:^5.0' 'symfony/finder:^7.0 || ^8.0'
```

## Configure the Checks

Create a `Configuration` with the application's own roots, PSR-4 namespaces, suffixes and discovery boundaries. No application layout is built into the package. See [Configuration](src/Configuration.php) for every required argument and the [fixture configuration](https://github.com/teylabs/standards/blob/main/tests/ConformanceTest.php) for a complete `src/Domain` + `src/UI` example.

In `tests/Arch/ConformanceTest.php`, import `Tey\Standards\Configuration` and `Tey\Standards\Conformance`, construct `$configuration`, then register tests in the consuming file:

```php
use Tey\Standards\Conformance;

$preset = new Conformance($configuration);

foreach (Conformance::TESTS as $rule => $name) {
    arch($name, fn () => $preset->assert((string) $rule));
}
```

Add `tests/Arch` to `phpunit.xml`. Run `vendor/bin/pest --testsuite=Arch --log-junit=conformance-junit.xml`; retain the file in CI as `conformance-junit` with the run's commit and conclusion. Missing, skipped or expired evidence is UNVERIFIED, not PASS.

## Add a Ratchet Entry

Pass an explicit allow-list to `Configuration::allowlist`. Each entry has `rule`, `target` (exact relative path or class), `check` (exact diagnostic), `reason` and a real `Y-m-d` `date`. Inspect `$preset->violations('20')` when reviewing a failure. Never build the allow-list by discovering current violations at test time. Fixed, deleted, duplicate and unknown-rule entries fail.

## Coverage and Limits

Rules 12, 14, 19, 20, 21, 22, 29 and 32 cover static PHP relationships and configured placement, not complete runtime behaviour. The no-dev check covers configured development namespaces, with safe attributes permitted only as imports and attributes. Literal `getPath` calls are prohibited; dynamic method calls are not resolved. Media classification uses the configured trait, including nested traits and inherited use. Money units cannot be inferred from casts, so rule 24 is not registered.

Pest's `laravel`, `security` and `php` presets run unchanged over the configured class inventory. Laravel's upstream structural expectations assume `App` and stock framework folders; other namespaces still get its forbidden-function checks, not a translated Laravel layout. Record modular conflicts individually. The Pest 4 lazy-result adapter uses internal APIs and is covered by negative fixtures; Pest 5 needs a separate compatibility review.

**Apps do not get `strict()`.** The package's own suite runs `arch()->preset()->strict()` only over its `Tey\Standards` source, excluding fixture and consumer namespaces. Its classes use strict types, are final and have no protected methods. The cookbook's consumer checks remain without the strict preset.

Run the package's tests with `composer install` and `composer test` in this directory. The fixture checks never execute their forbidden code. The package's discoverable service provider registers only its preflight command and core checks; architecture checks remain opt-in Pest tests. The package suite uses a lightweight container, not a full application.

## Deployment preflight

Add one deploy step **before migrate**, and stop deployment if it exits nonzero:

```sh
php artisan standards:preflight && php artisan migrate --force
```

CI can skip checks requiring production secrets with `--ci` and promote failed warning-level checks with `--strict`:

```sh
php artisan standards:preflight --ci --strict
```

Only `app.key`, `app.env-debug` and `database.config` are blocking core checks; mail checks have warning level and never block deployment. Blocking failures exit 1, warnings permit exit 0, and usage errors exit 2. `--strict` exits 1 on any selected failed check regardless of level, but advisory `warn` results still exit 0. CI secret skips are not passes. `--json` supplies board-readable levels and result types. Emergency `--skip=app.key,mail.transport` or `PREFLIGHT_SKIP` overrides are printed, logged and persisted until a passing check clears them. PHP CLI needs PCNTL for the five-second per-check guard. Checks inspect configuration offline; they do not prove that credentials work or services are reachable.

See the [preflight reference](https://github.com/teylabs/cookbook/blob/main/docs/reference/standards-preflight.md) for the complete check list, configuration, skip records and a copyable app payment-webhook check. Implement `Tey\Standards\Preflight\Check` with `name()`, `level()`, `needsSecrets()` and `run()`, returning a `Result`. Register it once in a service provider:

```php
$this->app->tag([\App\Support\Preflight\PaymentWebhookCheck::class], 'standards.preflight');
```

Alternatively, call `Tey\Standards\Preflight::register(CheckClass::class)` with the active container. Use a unique lowercase dotted name, keep checks fast and never include secrets in results. No payment checks ship in core.

## Verify after deploy

Open the environment's application log and find `PREFLIGHT SUMMARY`. Every run logs one line per selected check with `check`, `level`, `status` and `message`, followed by the summary: `exit_code`, `counts` for every status, `skips` (check names skipped in CI or by request), and the `ci`, `strict` and `only` options. An unset `only` is `null`. Usage errors log exit code 2 and the error without running checks. Read the check lines for the action to take; exit code 0 can still include warnings or skips.

These structured entries use the `standards` channel when configured, otherwise the application's default channel. Passing checks, CI skips and summaries log at `info`; failed checks, advisories and emergency skips retain `warning`. Configure the channel to retain `info` entries so successful deploys are visible too. Emergency skip lines also retain their reason, time and available Git commit.

For platforms that capture deploy-step stderr, reporting is on when stdout is not a TTY and off on a TTY. `--stderr` enables the summary plus failed and advisory check lines; `--no-stderr` disables that reporting and takes precedence if both flags are supplied. Emergency skip banners remain visible, on stderr in JSON mode. `--json` stdout keeps the same schema and bytes; reporting adds nothing to it. Hosts with a strict `mail:preflight` alias should forward both flags to `standards:preflight --only=mail --strict`; its summary then has `strict: true` and `only: mail`.

You can also rerun the command on the deployed environment through your hosting platform's command runner. For example:

```sh
cloud command:run <env> 'php artisan standards:preflight'
```

Read the runner's output and the new log summary. This checks the deployed configuration; it does not send mail or prove that services are reachable.

## Agent guidance

The package ships a Laravel Boost guideline. Select `tey/standards` during `php artisan boost:install` to include it in the application's agent instructions.

*Changelog: 2026-10-12 — trial `tey/standards` package with configured checks, exact stale-checked ratchets, seeded negative controls and a package-source-only strict preset.*
*Changelog: 2026-10-13 — added offline deploy preflight, registered checks, CI mode, timeout guards and persistent emergency skips; mail warns at deploy time, while generic --strict promotes failed results but not advisories for CI. (#105)*
*Changelog: 2026-10-13 — prepared v0.1.0 for public release, with production-only preflight dependencies, optional development architecture tools, no-dev boot coverage and Boost guidance. (#105)*
*Changelog: 2026-10-13 — log every preflight result and summary, add automatic and explicit stderr reporting without changing JSON stdout, and document verification after deploy. (#123)*
