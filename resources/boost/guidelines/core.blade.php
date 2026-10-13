# Tey Standards

`tey/standards` provides offline deployment configuration checks and optional, rule-tagged Pest architecture tests with an exact debt ratchet. Keep it in production `require`; architecture checks need Pest 4.7+, pest-plugin-arch 4.x and PHP-Parser 5.x in `require-dev`.

Run `php artisan standards:preflight` before deploy migrations and stop on a nonzero exit. Use `--ci` to skip production-secret checks in CI and `--strict` to fail on failed warning-level checks. Advisory warnings remain non-blocking. A skipped check is not a pass.

Add ratchet entries explicitly to `Configuration::allowlist`: one exact `rule`, `target` and `check`, with a reason and a real `Y-m-d` date. Review the diagnostic before adding it. Never discover or generate exceptions from current violations at runtime. Fixed or deleted debt fails as stale; remove its entry.

For an app check, implement `Tey\Standards\Preflight\Check` with a unique lowercase dotted `name()`, a `Level`, `needsSecrets()` and `run(): Result`. Tag its class `standards.preflight` in a service provider, or call `Tey\Standards\Preflight::register(CheckClass::class)` with the active container. Register once, bound any network timeout, and keep secrets out of messages.
