# Standards: Architecture Checks for Laravel

`tey/standards` checks configured PHP boundaries, placement and controller rules with named Pest tests. Each result carries a conformance rule number. A dated ratchet permits existing failures and rejects stale exceptions.

**Status: trial.** Requires PHP 8.4.1+ and Pest 4. This package is not published yet. Install its source with a Composer path repository and require it only in development:

```json
{
    "repositories": [{ "type": "path", "url": "packages/standards" }],
    "require-dev": { "tey/standards": "@dev" }
}
```

## Configure the Checks

Create a `Configuration` with the application's own roots, PSR-4 namespaces, suffixes and discovery boundaries. No application layout is built into the package. See [Configuration](src/Configuration.php) for every required argument and the [fixture configuration](tests/ConformanceTest.php) for a complete `src/Domain` + `src/UI` example.

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

Run the package's tests with `composer install` and `composer test` in this directory. The fixture checks never execute their forbidden code. The package has no service provider and does not boot an application.

*Changelog: 2026-10-12 — trial `tey/standards` package with configured checks, exact stale-checked ratchets, seeded negative controls and a package-source-only strict preset.*
