# Standards: Deployment Preflight and Architecture Checks for Laravel

`tey/standards` checks deployment configuration before migrate and checks configured PHP boundaries, placement and controller rules with named Pest tests. Each architecture result carries a conformance rule number. A dated ratchet permits existing failures and rejects stale exceptions.

**Status: trial.** Requires PHP 8.4.1+ and Laravel 13 components. Architecture checks additionally require Pest 4.7+ or Pest 5, the matching architecture plugin 4.x or 5.x and PHP-Parser 5.x.

## Install

Install as a production dependency so deployment preflight remains available after `composer install --no-dev`:

```sh
composer require tey/standards
```

Laravel discovers the service provider automatically. The production install does not require Pest or PHP-Parser. For architecture checks, install the optional tools in the application's `require-dev`:

```sh
composer require --dev 'pestphp/pest:^4.7 || ^5.0' 'pestphp/pest-plugin-arch:^4.0 || ^5.0' 'nikic/php-parser:^5.0' 'symfony/finder:^7.0 || ^8.0'
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

## MCP capability declarations (rule 55)

Rule `55` validates `docs/mcp-capabilities.json` schema v1. Override the project-relative path with the trailing `Configuration::mcpManifest` parameter. Every capability needs UI references, a shared operation, an implemented tool, resource or human-approval protocol, explicit scopes and existing host-owned parity test files. Write, external and protocol paths require their additional coverage categories and confirmation or handoff contracts. Semantic variants must match normalized options, defaults and ordered effects in both adapters and appear in the reviewed inventory in both directions.

Rule `55` also checks that every mutating route is declared. It parses the files under `routeRoots` (module globs such as `app/Modules/*/routes` included) without booting the app, and finds each `Route::post`, `put`, `patch`, `delete`, `any`, `match` with a mutating method, and the `store`, `update` and `destroy` routes of `Route::resource` and `apiResource` (honouring `->only()` and `->except()`). Group prefixes, name prefixes, middleware and `Route::controller()` are applied. Each route needs a capability that lists it in `routes`, by route name or as `"METHOD /uri"`: either a capability with its MCP path, or one marked `"uiOnly": "<reason>"`, which needs only an `id`, a non-empty reason and `routes`. A `uiOnly` capability has no MCP path, so the parity inventory must not name it. An undeclared route is reported against its route file and line, with its method, URI, name and controller action; a `uiOnly` without a reason is reported too. The check fails closed: a route file that does not parse, a `Route::` call with a non-literal URI or methods, or no route files at all is reported, not passed. Package routes (Fortify, Sanctum and the like) live in `vendor` and are never scanned. Auth, framework and broadcasting routes an app keeps in its own route files are excluded by `Configuration::mcpRouteExclusions`, `Str::is` patterns matched against the route name and `/uri` (default `McpParity::ROUTE_EXCLUSIONS`; pass your own list to replace it, or spread the default to extend it), and a webhook URI with signature-verifying middleware is excluded too. A route a capability lists that no route file defines is reported as stale. A single finding can also be allowlisted like any other. `standards:make` declares every mutating route it writes: in the `routes` of the manifest's `<table>.write` capability for `crud --mcp` when that capability exists, otherwise in a `uiOnly` entry `generated.<unit>.<name>` with the reason `generated: confirm or map to an MCP tool`, for you to confirm or map. It merges into an existing manifest and never duplicates a route; with no manifest it writes none.

An app that validates its manifest with a JSON schema can vendor [`docs/schemas/mcp-capabilities.schema.json`](docs/schemas/mcp-capabilities.schema.json) from this package. It accepts each capability as one of two shapes (`oneOf`): a full capability (`id`, `kind`, `ui`, `domain`, `mcp`, `tests` and `variants`, with optional `routes`), or a `uiOnly` entry, which requires only `id`, a non-empty `uiOnly` reason and a non-empty `routes` list, as rule 55 does. A `uiOnly` entry with an empty or blank reason, or without routes, fails the schema. The schema checks shape only; rule 55 still checks that every path and symbol exists.

Known blind spots of the route check:

- Routes registered in a service provider or anywhere outside the `routeRoots` files are not seen.
- Routes registered through a `$router` variable (or any receiver other than the `Route` facade) are not seen.
- `Route::webhooks()` routes are not enumerated.
- A webhook route is treated as signed when its URI contains `webhook` and a middleware name matches `signature`, `signed` or `verif`. This is a name heuristic; it does not prove the middleware verifies a signature.

References stay within allowed source roots. Hidden segments, traversal, symlinks and missing files fail. Package source references require a locked, installed package; parity tests remain host-owned. Findings use the manifest path and stable numbered diagnostics, never arbitrary manifest text or file contents, so exact dated ratchet entries work.

This is declaration validation only, equivalent to the standalone audit's `DECLARED` result. It does not execute tests, prove registration, discover every UI capability or establish business parity. Review inventory completeness and run the mapped tests separately. Package fixtures cover accepted and rejected declarations; the host repository also runs an opt-in PHP/Python differential test over the existing Python fixtures (`STANDARDS_PHP_PARITY=1 python3 -B -m unittest discover -s tests/conformance -p test_standards_mcp_parity.py`). The public package suite does not require Python.

### Rules 63–70

These rules remain manual. Their Detect and Verify requirements depend on application semantics or runtime evidence; source candidates alone cannot safely fail a ratchet. No additional rule key is registered for them.

| Rule                   | Probe key | Why                                                                                                                                           |
| ---------------------- | --------- | --------------------------------------------------------------------------------------------------------------------------------------------- |
| 63 · Proven secrets    | manual    | Requires a successful authenticated call per secret and environment; no static signal exists.                                                 |
| 64 · Form actions      | manual    | Detect explicitly calls for manual review; form state, sticky behaviour and leave warnings need browser evidence.                             |
| 65 · Hands-free deploy | manual    | Extra commands and sync hooks need context to identify required deploy work; migration idempotence and rollback need execution.               |
| 66 · Account mail      | manual    | The required message inventory and preference rules depend on account features and registered security listeners.                             |
| 67 · First run         | manual    | Reachable routes, provisioning modes, onboarding gates and empty states need request-level evidence.                                          |
| 68 · Wizard            | manual    | Real-state completion, optional-step gating and default handling cannot be inferred from step names or source matches.                        |
| 69 · Checklist         | manual    | Reconciliation and dismissal depend on real state, viewer and workspace boundaries.                                                           |
| 70 · Computed values   | manual    | Derived amounts cannot be distinguished soundly from recorded inputs or exempt counts; mappings and reconciliation need review and execution. |

## Add a Ratchet Entry

Pass an explicit allow-list to `Configuration::allowlist`. Each entry has `rule`, `target` (exact relative path or class), `check` (exact diagnostic), `reason`, a real `Y-m-d` `date` and a real `Y-m-d` `expires`. An entry without `expires` fails, in the Pest suite as in `standards:check` (`missing-expiry`). `php artisan standards:allowlist --stamp-expiry=YYYY-MM-DD` gives every undated entry that date in one go, written after its `date` line, or straight after the `'date' => '…'` pair in an entry written on one line. An entry with no literal date to stamp after (a date held in a variable, say) is skipped and listed, and the rest are still stamped. It never changes an existing expiry, finds the file through `tests/Arch/configuration.php` (or `--file=`), and supports `--dry-run` and `--json`. Inspect `$preset->violations('20')` when reviewing a failure. Never build the allow-list by discovering current violations at test time. Fixed, deleted, duplicate and unknown-rule entries fail.

## Coverage and Limits

Rules 12, 14, 19, 20, 21, 22, 29 and 32 cover static PHP relationships and configured placement, not complete runtime behaviour. The no-dev check covers configured development namespaces, with safe attributes permitted only as imports and attributes. A reference that runs only when its own class exists is permitted too: the `X::class` in `class_exists(X::class)`, an entry in the true branch of a ternary or `if` on `class_exists(X::class)` (such as `...(class_exists(X::class) ? [X::class] : [])` in `bootstrap/providers.php` for a dev-only provider), and an entry in `array_filter([...], class_exists(...))`. Its `use` import is permitted when every reference to the class in the file is guarded. A guard on another class, the else branch, or an unguarded reference beside a guarded one still fails. An app class that only loads on a dev install is not checked either: one registered only in `bootstrap/providers.php`, and only in the true branch of `class_exists(D::class)`, where D is a development class its own file extends or references (an app provider extending spatie/typescript-transformer's provider, guarded by that parent). This fails closed: any other reference to the class in a source or discovery root, `bootstrap/`, `config/` or `routes/` (a `config/app.php` provider entry, a `register()` call, its name as a string), or a guard on an unrelated or non-development class, and its file is checked as usual. Laravel's generated `bootstrap/cache/` (`services.php`, `packages.php`, `config.php`) is never scanned, by this or any rule: it is rebuilt on boot from what the app declares elsewhere. Every file-scanning rule (through `Tey\Standards\Ignored`) also skips Vite's `public/build/` and `public/hot` always, git or not, and whatever the app's git ignores: in a git work tree it asks `git check-ignore` (unless the enclosing repository ignores the app folder itself, when it falls back to the static read), which honours every `.gitignore`, `.git/info/exclude` and `core.excludesFile` and keeps a tracked file under an ignored pattern in scope. Without git it reads only the root `.gitignore`, statically (comments, `!`, anchors, trailing `/`, `*`, `?`, `[...]`, `**`); nested `.gitignore` files and global excludes are not seen there, and it cannot tell that a file is tracked, so an ignored pattern skips it. Nothing else is skipped. Literal `getPath` calls are prohibited; dynamic method calls are not resolved. Media classification uses the configured trait, including nested traits and inherited use. A trait that uses the media trait is not itself checked, since a trait cannot implement an interface; every class that uses it, directly or through another trait, is. Money units cannot be inferred from casts, so rule 24 is not registered. Rule 51 parses workflow YAML; see [CI triggers](#ci-triggers) for its limits.

Pest's `laravel`, `security` and `php` presets run unchanged over the configured class inventory. Laravel's upstream structural expectations assume `App` and stock framework folders; other namespaces still get its forbidden-function checks, not a translated Laravel layout. The `security` preset leaves out `Configuration::$securityExemptRoots` (default `database/seeders` and `database/factories`), where `rand()`/`mt_rand()` make fake data; app code stays checked. Its `unserialize` finding is dropped for a class whose file calls `unserialize()` only as `unserialize($data, ['allowed_classes' => [Foo::class, ...]])` (a literal `::class` list, empty allowed) or `['allowed_classes' => false]`, other keys alongside allowed. No options, `true`, a variable options array or `allowed_classes`, a spread, other keys only, or any one looser call in the same file keeps it. Blind spots: the check is per file, not per call site, so one class's finding covers all of its calls; and Pest's preset never sees `unserialize` used as a string callable (`array_map('unserialize', ...)`), so neither does this rule. Record modular conflicts individually. The shared Pest 4/5 lazy-result adapter uses reviewed internal APIs and is covered by negative fixtures under both majors. It preserves Pest 4 diagnostic wording for targeted method, base, suffix and attribute failures so exact ratchet entries survive a Pest 5 upgrade.

**Apps do not get `strict()`.** The package's own suite runs `arch()->preset()->strict()` only over its `Tey\Standards` source, excluding fixture and consumer namespaces. Its classes use strict types, are final and have no protected methods. The cookbook's consumer checks remain without the strict preset.

Run the package's tests with `composer install` and `composer test` in this directory. The fixture checks never execute their forbidden code. The package's discoverable service provider registers only its preflight command and core checks; architecture checks remain opt-in Pest tests. The package suite uses a lightweight container, not a full application.

## Rule 21: Thin Controller Bodies

Rule 21 retains every CRUDDY public-surface and route check. It also parses controller method bodies with PHP-Parser, resolves imported class aliases and reports one exact path + check per method:

| Check string (followed by ` \| PostController::store`) | Detects |
| --- | --- |
| `inline validation` | `Validator::make()`, global `validator()`, request `validate()` and `$this->validate()`, including in helpers and closures. Use FormRequests. |
| `non-response helper` | Private/protected methods other than the constructor or a response formatter. |
| `direct persistence` | Calls to `DB::` and recognised model/builder writes, including `create`, `update`, `save`, `delete`, `forceDelete`, bulk writes and quiet variants. |
| `multiple operations` | A resource action or `__invoke` constructs or invokes more than one distinct recognised Action/Query class. Repeated calls to the same class count once. |
| `response negotiation` | Request `wantsJson()`, `expectsJson()`, `ajax()` or `acceptsJson()`. Choose the response contract at the route boundary. Skipped only for a public method that overrides a same-named method of a vendor parent, resolved exactly as rule 21's vendor-override allowance (composer's maps, a parent under `vendor/`); every other body check still applies to it. |

**A vendor override is not app surface.** CRUDDY's `method` check skips a public method that overrides a method of the same exact name declared by a vendor parent, such as a controller extending `Laravel\Passport\Http\Controllers\AuthorizationController` and overriding `authorize()`. The parent is resolved statically through the app's `vendor/composer/autoload_classmap.php`, then `autoload_psr4.php` (no class is loaded), its file must lie under `vendor/`, and the walk follows vendor ancestors only. Blind spots, all of which still fail: a parent the maps don't resolve or whose file doesn't parse, an app-owned parent (even one that extends a vendor class), a name that differs in case, and a method the vendor parent gets only from a trait or an interface. The body checks below still apply to the override.

The default helper shape is exactly one `return` of `response()`, `redirect()`, `to_route()`, `back()`, an Inertia render/modal, or a Response/Redirect facade factory. Known response chains (including toast macros) are permitted. Arguments may contain data, but nested service calls, object construction, assignments and extra statements fail this shape. This intentionally narrow default never treats a response return type or a name such as `persist` as proof of formatting. A host can allow its own helper names with trailing `Configuration(controllerResponseHelperPattern: '/^(jsonResponse|pageResponse)$/', ...)`. Use named arguments with your existing configuration; the pattern defaults to `null`. Invalid patterns fail configuration. This opt-in only permits the helper: validation, writes and response negotiation inside it still fail independently.

Operation detection defaults to namespace segments `Actions` and `Queries` anywhere in the resolved class name, or class suffixes `Action` and `Query`. Override the trailing `controllerOperationNamespaces` and `controllerOperationSuffixes` arrays for the host's vocabulary; namespace paths match complete segments, never substrings. Suffixes match the short class name and only when it is longer than the suffix, so a class named exactly `Action` is not an operation. Classes in a `controllerNonOperationNamespaces` segment (default `['Data']`, for descriptor Data classes) never count. Empty arrays disable that classifier. The scan recognises static calls, new instances, typed action parameters, typed/promoted controller properties, local assignments, invokable objects and `app(X::class)` / `resolve(X::class)` calls. It flags orchestration of multiple classes, not the absence of an operation in a render-only action. Whether the chosen operation owns all business logic remains a review question.

To keep false positives low, a method named `save`, `validate` or `expectsJson` on an unrelated service is not enough. Model/builder receivers come from resolved parameter/property types, local assignments, construction and common fluent model chains; a `Models` namespace segment, Eloquent Model inheritance or an Eloquent Builder identifies them. Request receivers use Request types/subclasses, a `Request` suffix, `$request` or the global `request()` helper. Unknown relationship return types, untyped services, dynamic calls, inherited/trait method bodies, container strings and arbitrary factory results are not resolved. This is static candidate detection, not proof that every write or operation is found.

Each diagnostic uses rule `21`, a relative controller path and a stable check such as `inline validation | PostController::store`. Existing dated ratchet entries work unchanged, and stale entries still fail. Run `composer test` here for passing/failing fixtures, configuration controls, independent call forms and ratchet tests; run the host's rule-21 architecture test to review its own debt.

## Test tooling checks

`pest-only`, `php-tag`, `tia` and `boost` run through `Conformance::TESTS` and use the same exact, dated ratchet as the numbered rules.

- `pest-only` parses PHP under `tests/` without loading it. It flags PHPUnit or Laravel TestCase subclasses with `test*` methods or PHPUnit `#[Test]` attributes, including aliases and inheritance through test classes. `Configuration::testBasePaths` defaults to `['tests/TestCase.php']`; other `*Case.php` files are accepted when referenced by Pest's `extend()` or `uses()`. Configure additional base paths explicitly. External custom base-class ancestry and dynamically declared test methods are not resolved.
- `php-tag` fails every `*.php` file under the test roots (`testRoots`, default `tests`) and the source roots that doesn't open with `<?php`. PHP treats such a file as plain text: Pest prints it and runs nothing, so a whole spec goes dark without failing. Only what PHP allows may come first: a UTF-8 byte-order mark, then a `#!` line. Templates aren't checked: `*.blade.php` anywhere, and any `*.php` under a `resources/views` directory (a plain PHP template starts with its markup), since a module layout keeps views under the source roots. A test root that doesn't exist yet is skipped. Each offending file is named.
- **A configured root that doesn't exist yet** is skipped by every rule, never a crash: for example a fresh module's controller, ViewModel or page root, or an app that has not made its first controller. A glob that matches nothing is not counted, since it may match later. A full `standards:check` reports each missing root once as a `configuration` advisory, never as drift; a `--changed` run doesn't mention it.
- `tia` checks `tests/Pest.php` for the guard below: an argv assignment from `$_SERVER['argv'] ?? []`, all five conditions joined with `&&`, strict flag checks against that variable, and a direct integer `exit` from 1 to 255. Comments, zero exits and nested conditional exits do not count. This is a static guard-shape check, not proof that TIA has a coverage driver or a valid replay cache. Pest 4 apps can baseline this rule until their Pest 5 adoption.
- `boost` requires `laravel/boost` in Composer `require-dev`, a readable JSON object in `boost.json` that lists `agents` and `packages` (Boost 2.10's `boost:update` refuses to run without agents, and `boost:install` without packages deletes project skills; `standards:install` infers whichever is missing and never changes one that is set), `.ai/guidelines/`, and a non-empty generated `<laravel-boost-guidelines>` block in `AGENTS.md` or `CLAUDE.md`. Without the dependency it emits one prerequisite finding. It checks non-empty `SKILL.md` files for declared Composer and npm stack packages using `Configuration::boostSkills` (package name → list of skill names). Replace that map for another stack; unrelated packages add no requirements. `inertia-vue-development` is required only for `@inertiajs/vue3` 1 and 2, and `tailwindcss-development` only for Tailwind 3 and 4, the majors Boost 2.10.3 ships them for. `boostSkillRoots` defaults to `.claude/skills`, `.agents/skills` and `.codex/skills`. Boost generates skills into those (usually gitignored) directories per checkout, so a skill absent from every root still passes when the app regenerates it: `boost.json` lists it under `skills` (as `boost:install` records them), or a Composer `post-install-cmd`, `post-update-cmd` or `post-autoload-dump` script runs `boost:update` or `boost:install`. Whether the directories are gitignored does not matter. A skill file that is present is still checked, so an empty `SKILL.md` fails either way, and an absent skill nothing regenerates is reported with a hint to commit it or add one of those. This checks source declarations and generated files, not an installed vendor tree, guideline freshness or live MCP connectivity.

Paste this guard near the start of `tests/Pest.php`, after imports and before test registration:

```php
$argv = $_SERVER['argv'] ?? [];
if ((getenv('CI') === false || getenv('CI') === '') && getenv('PARATEST') === false && getenv('PEST_TIA') === false && ! in_array('--tia', $argv, true) && ! in_array('--no-tia', $argv, true)) {
    fwrite(STDERR, "Choose --tia for local tests or --no-tia for an explicit full run.\n");
    exit(1);
}
```

Run local Pest 5 tests with `vendor/bin/pest --tia` (PCOV or Xdebug is needed to record coverage). Use `--no-tia` for an explicit uncached run or browser specifications. CI (a non-empty `CI`) and parallel/TIA workers bypass the local guard; a set-but-empty `CI=` does not; CI still executes the full selected suite. The checker does not add the guard or configure TIA for the app.

## Deployment preflight

Add one deploy step **before migrate**, and stop deployment if it exits nonzero:

```sh
php artisan standards:preflight && php artisan migrate --force
```

CI can skip checks requiring production secrets with `--ci` and promote failed advisory-level checks with `--strict`:

```sh
php artisan standards:preflight --ci --strict
```

`app.key`, `app.env-debug`, `database.config` and the `install.*` group are blocking; mail, brand and CI checks have advisory level (`Level::Advisory`, JSON `"advisory"`; `Level::Warning` remains as a deprecated alias) and never block deployment. Blocking failures exit 1, advisory failures permit exit 0, and usage errors exit 2. `--strict` exits 1 on selected failed checks regardless of level, except `ci.triggers` until CI enforcement is enabled; advisory `warn` results still exit 0. CI secret skips are not passes. `--json` supplies board-readable levels and result types. An interactive run prints the full table; a non-interactive run (a deploy command, or `--no-interaction`) prints only failed, advisory and emergency-skipped checks, then one summary line such as `Preflight: 24 checks, 23 passed, 0 failed, 0 advisory, 1 skipped (exit 0).`, so a passing deploy never logs the word "warning". Emergency `--skip=app.key,mail.transport` or `PREFLIGHT_SKIP` overrides are printed, logged and persisted until a passing check clears them. PHP CLI needs PCNTL for the five-second per-check guard. Configuration checks run offline; `queue.worker` reads queue storage, `install.tables` reads the schema, and `ci.triggers` reads workflow files. No hosting APIs are called. These checks do not prove credentials for other services or that a worker is running.

See the [preflight reference](https://github.com/teylabs/cookbook/blob/main/docs/reference/standards-preflight.md) for the complete check list, configuration, skip records and a copyable app payment-webhook check. Implement `Tey\Standards\Preflight\Check` with `name()`, `level()`, `needsSecrets()` and `run()`, returning a `Result`. Register it once in a service provider:

```php
$this->app->tag([\App\Support\Preflight\PaymentWebhookCheck::class], 'standards.preflight');
```

Alternatively, call `Tey\Standards\Preflight::register(CheckClass::class)` with the active container. Use a unique lowercase dotted name, keep checks fast and never include secrets in results. No payment checks ship in core.

## Verify after deploy

Open the environment's application log and find `PREFLIGHT SUMMARY`. Every run logs one line per selected check with `check`, `level`, `status` and `message`, followed by the summary: `exit_code`, `counts` for every status, `skips` (check names skipped in CI or by request), and the `ci`, `strict` and `only` options. An unset `only` is `null`. Usage errors log exit code 2 and the error without running checks. Read the check lines for the action to take; exit code 0 can still include advisories or skips.

These structured entries use the `standards` channel when configured, otherwise the application's default channel. Passing checks, CI skips and summaries log at `info`; failed checks and emergency skips log at `warning`, and advisories at `notice`. Configure the channel to retain `info` entries so successful deploys are visible too. Emergency skip lines also retain their reason, time and available Git commit.

For platforms that capture deploy-step stderr, reporting is on when stdout is not a TTY and off on a TTY. `--stderr` enables the summary plus failed and advisory check lines; `--no-stderr` disables that reporting and takes precedence if both flags are supplied. Emergency skip banners remain visible, on stderr in JSON mode. `--json` stdout keeps the same schema and bytes; reporting adds nothing to it. Hosts with a strict `mail:preflight` alias should forward both flags to `standards:preflight --only=mail --strict`; its summary then has `strict: true` and `only: mail`.

**"0 failed jobs" means every store.** On a database or redis queue, `php artisan queue:failed` covers it. On a Laravel Cloud managed queue, also run `cloud managed-queue:failed-jobs <env> --json -n`: those failures never reach `failed_jobs` (see `queue.failed-store`).

You can also rerun the command on the deployed environment through your hosting platform's command runner. For example:

```sh
cloud command:run <env> 'php artisan standards:preflight'
```

Read the runner's output and the new log summary. This checks the deployed configuration; it does not send mail or prove that services are reachable.

## Nightwatch Readiness

Rule 54 applies only to running (deployed) applications, never packages; CI checks application readiness before deployment. `nightwatch.ready` reuses `CiTriggers::distribution()`: `preflight.ci.distribution` first, Composer type next (`project` is an app; any other or missing type is a package), and `artisan` only without a Composer manifest. Packages return `skipped` without warnings, including under `--strict`.

`nightwatch.ready` requires `laravel/nightwatch` in Composer `require`, a readable `config/nightwatch.php`, and effective `nightwatch.sampling` rates: requests between 0 and 0.1, exceptions, commands and scheduled tasks at 1.0. It is advisory-level and needs no token: failures exit 0 normally and 1 under `--strict`, including in CI. Select it with `php artisan standards:preflight --only=nightwatch --ci --strict`.

`deploy.migrate` checks that every deploy runs `php artisan migrate --force`. Packages rely on Laravel's `MigrationsEnded` and `NoPendingMigrations` events firing on each deploy.

- **Where it looks:** only at what the repository commits:
  - the paths in `preflight.deploy.script` (`PREFLIGHT_DEPLOY_SCRIPT`, comma-separated);
  - a Laravel Cloud config (`cloud.yml`, `cloud.yaml`, `.cloud/`);
  - Forge or Envoyer scripts (`deploy.sh`, `deploy`, `.forge/`, `forge-deploy.sh`, `.envoyer/`, `Envoy.blade.php`);
  - `scripts/deploy*` or `bin/deploy*`;
  - a `.github/workflows/*deploy*` workflow;
  - a composer script whose name contains `deploy`.
- **What counts:** `artisan migrate … --force`, uncommented. `migrate` without `--force`, and `migrate:fresh` or other subcommands, don't.
- **Outcomes:**
  - It passes when a found script has the step, and fails when found scripts don't (or a declared path isn't committed).
  - It warns when nothing is found, which is the case for a Laravel Cloud deploy configured only in its dashboard, which preflight can't read. The hint is to commit the deploy commands and declare the path.
- **Level:** advisory, so it exits 1 only under `--strict`. Packages are skipped. Select it with `php artisan standards:preflight --only=deploy`.

Run without sampling overrides to check defaults, and against cached production config to check deployed rates. The app must commit the config, default requests to 0.1, cap overrides at 0.1 and stay dormant without `NIGHTWATCH_TOKEN`; prove those source and boot contracts in the app's tests. Preflight does not inspect Git tracking or prove ingestion. Deploy notes should say Laravel Cloud → Connect Nightwatch is the only remaining step. The check arrived in 0.7.0.

## Installed drivers

The `install` group proves that every configured driver can actually run. Each check is blocking: a failure exits 1 in deploy mode without `--strict`. They read resolved config, Composer's installed metadata (`InstalledVersions`) and loaded PHP extensions; messages name the config key and what is missing, never a value. Packages that are not apps skip the group, using the same distribution detection as rule 51. Select it with `php artisan standards:preflight --only=install`.

| Check | Proves | Limits |
| --- | --- | --- |
| `install.mail` | Each transport the default mailer resolves to (failover and roundrobin children included) has its package: `resend` → `resend/resend-php`, `postmark` → `symfony/postmark-mailer` and `symfony/http-client`, `mailgun` → `symfony/mailgun-mailer` and `symfony/http-client`, `ses`/`ses-v2` → `aws/aws-sdk-php`. | Mailers other than the default are not checked; stock entries in `config/mail.php` stay unused. |
| `install.filesystems` | Each selected disk (`filesystems.default`, `filesystems.cloud`, Media Library's disks) and each disk with a `bucket` or `host` has its adapter: `s3` → `league/flysystem-aws-s3-v3`, `ftp` → `league/flysystem-ftp`, `sftp` → `league/flysystem-sftp-v3`. `scoped` disks resolve to their parent. A selected disk must exist. | Custom `Storage::extend` drivers are not checked. Disks chosen only in code are found only when their bucket or host is set. |
| `install.drivers` | The default queue connection, cache store (and failover stores) and session driver have their client: `redis` → the phpredis extension or `predis/predis`, as `database.redis.client` selects; `sqs` and `dynamodb` → `aws/aws-sdk-php`; `memcached` → the extension; `beanstalkd` → `pda/pheanstalk`. | Extensions are probed with `extension_loaded()`, since they have no Composer package. Non-default connections are not checked. |
| `install.tables` | When session, cache, queue or failed-job storage is `database`, the configured tables exist on the configured connection: `sessions`, `cache` and `cache_locks`, `jobs` and `job_batches` (for a database queue), `failed_jobs`. Uses the schema builder. | Skipped in CI (`--ci`). An unreachable database fails as "Not checked" without exception details. Columns are not compared. |
| `install.extensions` | Every `ext-*` in `composer.json` `require` and in the locked runtime packages (`composer.lock` `packages`) is loaded. Without a lock file it scans `app/` for `bcmath` and `intl` calls instead. `gd` or `imagick` is loaded when `image.driver` names it, or when Media Library is installed, `app/` registers conversions and `media-library.image_driver` names it. | The conversion and fallback scans are text matches. Dev packages are ignored. |
| `install.secrets` | `APP_KEY` (and `app.previous_keys`) decode to the cipher's key length; Passport's keys (when installed), from config or the `oauth-*.key` files, are PEM with real newlines, not literal `\n`; no secret-named config key (`*key`, `*secret`, `*token`, `*password`, `*passphrase`) is a run of asterisks, `placeholder`, `changeme` or `secret`; the default mailer's API secret is not empty. | Skipped in CI (`--ci`). Credentials are not tried against their services. |
| `install.vite` | When a Blade view under `resources/views` uses `@vite`, `public/<build>/manifest.json` exists and is JSON. Set `preflight.vite.build_directory` to match `Vite::useBuildDirectory()`. | Entries in the manifest are not compared with the views. |
| `install.sessions` | When `laravel/passport` is installed, or `preflight.sessions.consent` is true (an MCP consent screen without Passport), the resolved `session.driver` is a server-side store, not `cookie` or `array`. When the config has no driver, the check falls back to `SESSION_DRIVER` in the environment, and fails when neither is set. The resolved config is the evidence because a platform's deploy step need not expose its variables to the process. OAuth consent keeps its authorization request in the session, which passes a cookie's 4KB limit. | Skipped in CI (`--ci`) and in the `local` and `testing` environments. The driver's own storage is checked by `install.drivers` and `install.tables`. |

Run the deploy step after the asset build and with the production database reachable, or `install.vite` and `install.tables` fail. The group arrived in 0.7.0.

## Brand: an app-owned favicon set

Ship the app's own `public/favicon.svg` with an `<svg` root, a non-empty `public/favicon.ico` with the ICO header `00 00 01 00` and 16×16 and 32×32 directory entries, and a PNG `public/apple-touch-icon.png` of exactly 180×180 pixels. Extra ICO sizes are allowed; a zero width or height byte means 256. Link them in every root layout, including guest, app and error shells. A plain monogram on a filled tile is a fallback for an app without a mark. Check it at 16px against light and dark tab bars. Do not retain Laravel's starter icons or a `data:` suppressor.

`brand.favicon` is advisory-level and reads files only, so it runs in `--ci` without secrets. A missing, unreadable or empty file, a wrong format or size, a known default in any of the three files, or a root layout with no non-data `rel="icon"` link returns `fail`: plain runs exit 0 when the blocking checks pass, and `--strict` exits 1. Each asset failure names the file and the problem. PNG type and dimensions use `getimagesize`; GD is not needed. Select it with `php artisan standards:preflight --only=brand --strict`.

The default source is `resources/views/<inertia.root_view>.blade.php`, with dot notation resolved to directories and `app` used when unset. Override it with every root Blade layout for Livewire, Filament or additional shells. A missing or unreadable layout returns **Unknown**, an advisory `warn` even under strict mode; a broken link in another found layout still fails. Links are matched case-insensitively from source, excluding HTML and Blade comments. No Blade rendering or HTTP request occurs.

Publish `config/preflight.php` with `php artisan vendor:publish --tag=standards-preflight-config`:

| Configuration | Default | Meaning |
|---|---|---|
| `preflight.brand.layouts` | `[]` | Use Inertia's root view, or override with a list of layout paths relative to the application base path. Every listed layout is inspected. |
| `preflight.brand.known_default_hashes` | `[]` | Add SHA-256 strings to the built-in defaults; does not replace them. |

### Known Laravel defaults

[`CoreCheck::KNOWN_DEFAULT_HASHES`](src/Preflight/CoreCheck.php) is the single detection list: **the checker compares SHA-256 only**, including configured additions. SHA-1 below hashes the same raw file bytes, not Git's blob header; it is reference only for apps that maintain their own icon tests. Never mix the columns in one comparison.

The table covers every distinct version of the three public paths in the full Git histories of `laravel/laravel` and the Vue, React, Svelte and Livewire kits, across reachable branches and tags, audited 2026-10-13. Duplicate bytes share a row. The skeleton currently ships only the empty ICO. All four kits currently share the three rows labelled “current kits”. Their audited heads are [Vue](https://github.com/laravel/vue-starter-kit/commit/d282e817c6c2fa1bd475f7c42ea785ccfc67d0ab), [React](https://github.com/laravel/react-starter-kit/commit/717b8f55aefd82d25d4119eaebdc8e3a72b8d7e5), [Svelte](https://github.com/laravel/svelte-starter-kit/commit/76d6cf46b6cc7236121a75b9e4f1bea8809cb490) and [Livewire](https://github.com/laravel/livewire-starter-kit/commit/0f62a26c4e4b401c1300930f47d72a497add8cce); the [skeleton head](https://github.com/laravel/laravel/commit/f4000aeb018fcbf71d4a13e3ee4b80c7e2d45be5) has the empty file. Source links below pin one occurrence of each version.

| File | Source | sha256 | SHA-1 |
|---|---|---|---|
| `favicon.ico` (empty) | [laravel/laravel](https://github.com/laravel/laravel/blob/26730cd5b3ceae03ffc5549e85b67d9c94e861ef/public/favicon.ico) | `e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855` | `da39a3ee5e6b4b0d3255bfef95601890afd80709` |
| `favicon.ico` | [current kits](https://github.com/laravel/vue-starter-kit/blob/e09dac8da170bba3eabd72a38cbc1c3f12171d6b/public/favicon.ico) | `4606a56e6ef3f5ec39201497f57069d5457ce9cea25227134d0ba378788e9070` | `4de5db574e6c589ef763ce57c4061f2269648ca4` |
| `favicon.svg` | [current kits](https://github.com/laravel/vue-starter-kit/blob/e09dac8da170bba3eabd72a38cbc1c3f12171d6b/public/favicon.svg) | `242f4f8f93f5fbc6c8aeec500c9bac02dcfe68daba166d484c5cdc986c88d8ee` | `139952bf6929a4d223bec823dc1953798895ec6c` |
| `apple-touch-icon.png` | [current kits](https://github.com/laravel/vue-starter-kit/blob/e09dac8da170bba3eabd72a38cbc1c3f12171d6b/public/apple-touch-icon.png) | `4001aa032ff113e1a268a9bbf1ab0fd9949439f9f54a85895956eb323aba977d` | `cdedf5b84942a5511948d1851ecd2c7930b5b1b9` |
| `favicon.ico` | [Vue earlier](https://github.com/laravel/vue-starter-kit/blob/dc5bb6d1e2739156059b962f516369fb34f812d1/public/favicon.ico) | `bf8593aec29b1ed018f6ea5299eadd19eaccf5afce3a23bec2ac9592de73ff4c` | `3d4737b1c6bbf228253498bc4121980fb73fb357` |
| `favicon.svg` | [Vue earlier](https://github.com/laravel/vue-starter-kit/blob/dc5bb6d1e2739156059b962f516369fb34f812d1/public/favicon.svg) | `0d1a66fd5d0a10327508953be9c6fa502a3d9d466fb505a934df06cffbddf8a2` | `f8bff57e1b1ff9d83bf1ca1d738fde6532a2f217` |
| `apple-touch-icon.png` | [Vue earlier](https://github.com/laravel/vue-starter-kit/blob/dc5bb6d1e2739156059b962f516369fb34f812d1/public/apple-touch-icon.png) | `c359b8254a8a8a0137d9ec725627d28dbbbb1b543fca813e23d76a61870399f3` | `f8e12e68b98ba74e4d38361ac5b737415addb721` |
| `favicon.ico` | [React earlier](https://github.com/laravel/react-starter-kit/blob/108963ea46bf55045a73504b57ecff70ee2181a4/public/favicon.ico) | `49eacef4006afcbef309d58d5e134dc118b993faa7a5a00d25c24048b2feb9cc` | `374e066b7af32f9de0fd48886061476540f26f2b` |
| `favicon.svg` | [React earlier](https://github.com/laravel/react-starter-kit/blob/108963ea46bf55045a73504b57ecff70ee2181a4/public/favicon.svg) | `911847ef8aab1657b6395cbceca1f21bfd860e5736dc5d059ff3ba8c2270c1fb` | `ef42ae30126583cc83785027bb7bcf858d341e46` |
| `apple-touch-icon.png` | [React earlier](https://github.com/laravel/react-starter-kit/blob/108963ea46bf55045a73504b57ecff70ee2181a4/public/apple-touch-icon.png) | `61a028f9d9e1fb30365982c5b9a5f283d6847c0f9906b9127d991d23c1e814e5` | `e7b3f4ebdc2e18be96b50eb97c236f90cee4b5bd` |
| `favicon.ico` | [Livewire earlier](https://github.com/laravel/livewire-starter-kit/blob/50ff1b57279c6c93dc0d7f40269c8b8170f07493/public/favicon.ico) | `10712d787bbfd3c49b98a60839220ba4a10fa02c8b8b7e5c1f3d7eb61171f0dc` | `89fd70e8890ffbecc518a703d3055d7224857f30` |
| `favicon.svg` | [Livewire earlier](https://github.com/laravel/livewire-starter-kit/blob/50ff1b57279c6c93dc0d7f40269c8b8170f07493/public/favicon.svg) | `da6a60c37187f21fc7152793daf4b4685626554e85c7de3f0a6e192def174ff0` | `5515ef615c56f3f4d6f035cc2f3580c39c7d1c46` |
| `apple-touch-icon.png` | [Livewire earlier](https://github.com/laravel/livewire-starter-kit/blob/50ff1b57279c6c93dc0d7f40269c8b8170f07493/public/apple-touch-icon.png) | `cf5ee2ae15971b85a6850289b9176d4a23e9e69c6acace8aeb206bb946bfe947` | `b64abf45030cd5febe01beb9da3d5aa8b23de3c6` |
| `favicon.ico` | [skeleton earlier](https://github.com/laravel/laravel/blob/a188d62105532fcf2a2839309fb71b862d904612/public/favicon.ico) | `1b8a202e0e4965bf8fd5740b95d4678d8183eba32b8f203acc4ec3be9016245e` | `44187157b030d5a936e75e6c10bff40afba052aa` |
| `favicon.ico` | [skeleton earlier](https://github.com/laravel/laravel/blob/6285eea340ae2756213f2d879f30fcc9cc8110bb/public/favicon.ico) | `8c7bdd0465674177c249e68b8cf92a57b9071997d48e2283c47a69db6680b3ca` | `340d17dea62029ed6dd4505cd4d392390ac53693` |
| `favicon.ico` | [skeleton earlier](https://github.com/laravel/laravel/blob/d5355a7a52eaad2695fee8ea0aefb0197b5dc38a/public/favicon.ico) | `382862f617fca26f9ccaeb98dc3944d6f977a43af3c15f2df5605c45ea23a8c5` | `1829829924b6d4a21486590152dcc766833e6cb2` |
| `favicon.ico` | [skeleton earlier](https://github.com/laravel/laravel/blob/90b33ddc1aff273ecb6c31d97f075060ea7aed41/public/favicon.ico) | `8ada36385f6d68aa53f6fab3865588c4696f72f4cd55c50c4457d2d3d5cf8380` | `1521af4cd7fa6599f44ea34813f49597d8a3b6b3` |
| `favicon.ico` | [skeleton earlier](https://github.com/laravel/laravel/blob/67fc2af3f4de208c3407da8eaa3dc1fb6d50847b/public/favicon.ico) | `2194a04330d5715154b60ec604ff539b10570baffe8e2427dc485b12cc0d64b9` | `1cd691781e3a3543d47604e992b54395c66b2307` |
| `favicon.svg` | [React intermediate](https://github.com/laravel/react-starter-kit/blob/fe4281b79e6f4a528287f0f580343cc777ea897c/public/favicon.svg) | `99ef06a5e4e694047ee33176b8eef29ee5867fbec6af7b45caa273d6571fe1cc` | `df6169130b4f06008ede1c75ab9daa31073edbae` |

Tests retain eighteen non-empty byte-exact defaults, generate the empty file, and pin each kit's current three files. The preflight checks headers, directory sizes, PNG dimensions, the SVG root and source icon links. It does not decode every raster payload, fully validate XML, request asset URLs or discover every rendered shell. Verify rendered links and legibility in the application's own rule-50 tests and visual review.

## CI triggers

**Since 0.4.1, rule 51 is advisory by default.** New check groups arrive advisory, and an app opts in to enforcement per group, so a minor bump never turns CI red. Run `php artisan standards:preflight --ci --only=ci` once, fix the findings or record exact dated architecture ratchet entries, then set `PREFLIGHT_CI_ENFORCE=true` in CI. Pass the same config switch to `Configuration` below. Rebuild cached config after changing it. Ratchets apply to the architecture test; strict preflight still requires fixing its findings.

CI runs once per change that matters. **Apps: Linux only, on every event**, with one deploy PHP version on PRs; extra PHP versions run nightly or manually. **Distributed packages: test Windows on ready PRs, before merge**, regardless of cost, and macOS where relevant (advisory). Others run them on those platforms. Distribution includes published packages, private-but-sold packages and generated output others use; an app is our own code deployed by us on Linux. Package PHP matrices may run on PRs. This is rule 51; the [CI triggers recipe](https://github.com/teylabs/cookbook/blob/main/docs/recipes/backend/ci-triggers.md) has the full workflow and required-check caveat.

```yaml
on:
  push:
    branches: [main]
  pull_request:
    types: [opened, synchronize, reopened, ready_for_review]

concurrency:
  group: ${{ github.workflow }}-${{ github.event.pull_request.number || github.ref }}-${{ github.event.pull_request.draft }}
  cancel-in-progress: ${{ github.event_name == 'pull_request' }}

jobs:
  tests:
    if: github.event_name != 'pull_request' || github.event.pull_request.draft == false
    runs-on: ubuntu-latest
    timeout-minutes: 30
```

A push followed by `gh pr ready` sends a draft-snapshot `synchronize` and a `ready_for_review` event. A shared group lets the draft run cancel the full run, leaving a green PR with no suite.

Include the draft state in the workflow group whenever any job has a draft guard, and in each guarded job's own group. Unguarded workflows and unguarded jobs' own groups are exempt.

Since 0.6.1, public repos may set `drafts: full` to test drafts fully. The default `lint` keeps draft guards, draft-aware concurrency and the `ready_for_review` requirement. `full` exempts only those three requirements; packages still need Windows on ready PRs, and a Windows leg that runs on drafts too counts. Visibility comes only from readable `$GITHUB_EVENT_PATH` JSON (`repository.private`), never the network: private repos get `drafts-full-private` and retain `lint`; unknown visibility honours `full` with a `drafts-full-unverified` advisory. Invalid config/env values produce a `drafts-invalid` requirement naming the value.

CI-specific requirements apply only to workflows whose `on` includes `push` or `pull_request`, in string, list or map form. Workflows triggered only by `pull_request_target`, `issue_comment`, `pull_request_review_comment`, `release`, `schedule`, `workflow_dispatch`, `workflow_run` or `merge_group` are exempt from trigger, concurrency, draft-guard and matrix findings. Mixed workflows keep these checks on every job, even jobs selected for another event. **Every job in every workflow still needs `timeout-minutes`: a hung bot job can burn up to 360 minutes.**

`Tey\Standards\CiTriggers` parses `.github/workflows` offline. Construct it with the base path, default branches (`['main']`), optional `distribution: 'app'|'package'` and `drafts: 'lint'|'full'` (default `lint`); `eventPath` optionally overrides `$GITHUB_EVENT_PATH` for tests. `findings()` returns failures as `workflow`, `job` and `reason` entries. `advisories()` returns suggestions separately in the same shape. A workflow-level entry has `job: workflow`; repository-wide coverage targets `.github/workflows`. Each reason starts with its category and says what to change:

| Category | Finding |
|---|---|
| Push branches | A `push` trigger without `branches`, or with branches other than the default branches. |
| Pull request types | A job has a draft guard, but `pull_request.types` does not include `ready_for_review`. |
| Concurrency | No concurrency on the workflow or on every job, or `cancel-in-progress` that can cancel a main run. |
| `concurrency-draft` | A workflow with any draft-guarded job, or a guarded job's own concurrency group, omits `github.event.pull_request.draft` from its group expression. |
| Draft guard | A test job runs on draft pull requests. |
| Timeout | A job has no `timeout-minutes`. |
| PHP versions | An app runs more than one PHP version on pull requests. Packages are exempt. |
| `os-matrix` | An app has any non-Linux runner or OS matrix entry, on any event. |
| `windows-gap` | A distributed package has no Windows leg on a ready PR, across all workflows. Main/nightly-only coverage does not count. |
| `drafts-invalid` | The configured drafts value is neither `lint` nor `full`; lint requirements still apply. |
| `drafts-full-private` | `full` is ignored because the event payload confirms a private repository. |
| `drafts-full-unverified` (advisory) | Repository visibility could not be confirmed; `full` is honoured. |
| `cache` (advisory) | A job installs Composer/npm dependencies without a recognised dependency cache. Suggest measuring, never fail. |

Lint and static jobs may run on drafts. Package Windows jobs may use the draft guard: ready PRs still run them. Missing macOS and docs-only skipping are advisory.

Detection, in order: `preflight.ci.distribution` (`app` or `package`, env `PREFLIGHT_CI_DISTRIBUTION`); Composer type `project` → app, `library`, `laravel-package`, `composer-plugin` or another non-project type → package, with a missing type defaulting to `library`; only without `composer.json`, `artisan` at the base path → app; otherwise advisory Unknown with config instructions. Generated output or sold apps use `package` explicitly if their Composer type says `project`. Repository access plays no part.

**Consolidate small jobs first.** GitHub rounds each job up to whole minutes; tiny split jobs pay that tax and repeat setup. 2m43s → 2m12s still bills three minutes: savings begin below 2:00. Cache only above ~45 s cold installs or just over a minute boundary. A measured warm Setup of 31 s → 26 s plus ~5 s restore saved about zero, against ±20 s runner noise. The advisory recognises lockfile-keyed `actions/cache`, `setup-node` with `cache: npm`, setup-php plus an `actions/cache` step on the Composer cache dir, or `ramsey/composer-install`. Composer `install`/`update` and npm `ci`/`install`/`i` without these get a suggestion, never a failure.

The `[rule 51]` architecture test skips while enforcement is off, with an advisory reason, finding count and the command to see findings. `violations('51')` still returns every finding with the workflow as the target and `job | reason` as the check. With enforcement on, the test enforces the existing ratchet, including stale entries. Record existing debt as exact, dated ratchet entries. Pass the preflight's options to `Configuration` so both judge the same workflows and enforcement switch. Read Laravel config inside the test callback after the application boots, not while Pest registers tests; the [preset example](https://github.com/teylabs/cookbook/blob/main/docs/reference/standards-preset.md#configure-roots) shows that setup:

```php
new Configuration(
    // ...the existing arguments
    ciDefaultBranches: config('preflight.ci.default_branches', ['main']),
    ciDistribution: config('preflight.ci.distribution'),
    ciEnforce: config('preflight.ci.enforce', false),
    ciDrafts: config('preflight.ci.drafts', 'lint'),
);
```

`ci.triggers` is an advisory-level preflight check in the `ci` group. It runs with `--ci` only; deploy runs report `skipped`, not a pass. Requirements still report `fail` in text, JSON and logs when enforcement is off, but `--strict` exits 0 for those findings. With enforcement on, `--strict` exits 1 as in 0.4.0. Plain runs exit 0 either way when blocking checks pass; blocking checks are unaffected. Cache/macOS/Unknown advisories remain `warn` and exit 0 even under strict, with suggestions in both the message and JSON. Missing workflows are also advisory Unknown. After opting in, select it with `php artisan standards:preflight --ci --only=ci --strict`.

| Configuration | Default | Meaning |
|---|---|---|
| `preflight.ci.enforce` | `false` | Boolean via `PREFLIGHT_CI_ENFORCE`; opt in to strict preflight failures and architecture rule 51 enforcement. |
| `preflight.ci.default_branches` | `['main']` | Branches or globs a `push` trigger may list, such as `['main', '*.x']` for release branches. |
| `preflight.ci.distribution` | `null` | `app` or `package` via `PREFLIGHT_CI_DISTRIBUTION`; otherwise Composer type, then `artisan`, then Unknown. |
| `preflight.ci.drafts` / `Configuration::ciDrafts` | `lint` | `lint` or `full` via `PREFLIGHT_CI_DRAFTS`; only public repos may opt out of draft requirements with `full`. |

`Configuration::ciDefaultBranches` accepts the same globs. List the exact glob in both the configuration and `on.push.branches`; GitHub matches release branches such as `1.x` against `*.x`, while the checker compares the declared patterns.

### Packages

Packages without `artisan` use the Laravel-free CLI in `tey/standards` 0.6. Run it as **its own global-tool job**, not as a dev dependency: tey/standards needs PHP ^8.4.1 and Laravel 13 components, so in a package's `require-dev` it breaks matrix legs on older PHP or Laravel. The job installs it globally on PHP 8.4 and checks the repository it runs in:

```yaml
  standards:
    runs-on: ubuntu-latest
    timeout-minutes: 5
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.4', tools: 'composer:v2', coverage: none }
      - run: composer global require tey/standards:^0.6 --no-interaction --no-progress
      - run: "$(composer global config bin-dir --absolute)/standards ci --strict"
```

The job is cheap and needs no draft guard. Apps, which already require tey/standards, use `standards:preflight --ci --only=ci` instead.

Packages normally keep their settings in their root `composer.json` under `extra`, rather than Laravel config:

```json
{
    "extra": {
        "tey-standards": {
            "ci": {
                "default-branches": ["main", "*.x"],
                "distribution": "package",
                "drafts": "full",
                "enforce": true
            }
        }
    }
}
```

Run once without enforcement, fix the findings, then opt in. The CLI calls the same `CiTriggers` parser as app preflight; it does not boot Laravel, read `.env` files or require Pest/PHP-Parser. Where the CLI is installed in a project's own `vendor` instead, run `vendor/bin/standards ci --strict`, or `php vendor/bin/standards ci --strict` on Windows.

The example opts a public package into full draft tests; omit `drafts` or set `lint` to keep the default draft policy.

```sh
vendor/bin/standards ci [--strict] [--json] [--base=<dir>] [--default-branches=main,*.x] [--distribution=app|package] [--drafts=lint|full] [--enforce]
```

Settings resolve in order: flags, process environment, `extra.tey-standards.ci`, defaults. `--base` defaults to the current directory. `--default-branches` and `PREFLIGHT_CI_DEFAULT_BRANCHES` are comma-separated names or globs; `extra` accepts a list. `PREFLIGHT_CI_DISTRIBUTION` overrides `distribution`, and `PREFLIGHT_CI_ENFORCE` overrides `enforce` (true/false, 1/0, yes/no or on/off). `--enforce` enables enforcement for that run. Defaults are `['main']`, auto-detected distribution and enforcement off.

| Option | Environment | Composer `extra.tey-standards.ci` | Default |
|---|---|---|---|
| `--drafts=lint\|full` | `PREFLIGHT_CI_DRAFTS` | `drafts` | `lint` |

Human output lists requirement findings in a workflow/job/finding table, then advisories and a count/enforcement/exit summary. `--json` writes only `{"schema":1,"findings":[],"advisories":[],"enforce":false,"exit_code":0}` to stdout, with each finding or advisory using the parser's `workflow`, `job` and `reason` fields. Exit 1 requires all three: `--strict`, enforcement on and requirement findings. Otherwise findings exit 0; cache/macOS/Unknown advisories never fail. A missing workflows directory is an `unknown` advisory, but does not suppress invalid drafts or private-repo requirements. Unknown commands/options and invalid flag values (including `--drafts=bogus`) exit 2 with usage on stderr; invalid drafts from env or `extra` remain requirement findings. No command or `--help` prints usage; `ci` is the only command.

**Required checks.** `paths-ignore` suits repositories without required checks. With required checks, a skipped workflow never reports and blocks the merge; use a `changes` job that lets expensive jobs skip, since a job skipped by its `if` reports success. The checker does not read branch protection, so confirm the required check names by hand.

**Limits.** The checker does not run GitHub's expression engine. Ready-PR coverage resolves literal runners and matrices, include/exclude rows, `fromJSON` lists and simple event/draft boolean conditions and `condition && value || fallback` selectors. Unknown expressions do not prove Windows coverage; use a detectable leg or review and ratchet an inapplicable finding. App OS checks inspect all literal alternatives, including event-selected legs. PHP expressions choosing on `github.event_name` retain the existing exemption and need review. Reusable workflows, composite actions and wrapper scripts are not followed. One exception: a job whose `uses:` is `teylabs/standards/.github/workflows/standards.yml@<any ref>` takes its runner and timeout from `with.runs-on` and `with.timeout-minutes`. A missing `with.timeout-minutes` passes, because the workflow's default of 15 minutes is bounded; a present one must be a positive whole number, so zero, negatives, fractions and non-numeric values fail. Any other `uses:` job, including local or third-party reusable workflows, is judged as before and needs its own `timeout-minutes`. A job's own `timeout-minutes` must also be a positive whole number (an integer or a string of digits); zero, negatives, fractions and text fail. A `${{ … }}` expression, such as `${{ inputs.timeout-minutes }}` or `${{ matrix.timeout }}`, cannot be resolved statically, so it passes unchecked, in a job or in `with:`. The test scan recognises `php artisan test`, Pest, PHPUnit, `npm test`, `composer test`, Playwright and Vitest. Cache detection reads steps, not performance.

**This package's CI.** The standalone workflow runs PHP 8.4/8.5 with Pest 4 and 5 on Ubuntu for ready PRs and main. Windows runs Pest 5 only to keep the matrix lean, loads PDO SQLite and runs architecture, parser, backlog and command-contract tests. Every leg runs `php bin/standards ci --strict` with enforcement on. Production smoke installs the package as a dependency and runs `php vendor/bin/standards ci --json` on both platforms, checking that no Laravel `Application` was loaded; Windows then verifies deployment preflight's missing-PCNTL refusal. A separate temp-app job installs the package beside Pest 5 and executes a `[rule 20]` architecture test with clean and negative controls, plus the preflight smoke test. Test-only signal doubles exercise non-alarm command behaviour without changing production code. Only the busy custom-check timeout, busy backlog timeout and real deployment smoke test skip without PCNTL; each has a 2026-10-13 reason. A separate process verifies that the real preflight command still exits 2 without PCNTL rather than silently losing its timeout guard. Cookbook CI normally runs Linux only; issue #145 proves the package on GitHub's Windows runner with a temporary cookbook job removed before review.

## Queue: sync until needed

**Sync until needed; when async work is genuinely needed, use a scale-to-zero managed queue, not an always-on worker.** An app without async work runs `QUEUE_CONNECTION=sync`. Use the hosting platform's managed queue ([Laravel Cloud managed queues](https://cloud.laravel.com/docs/queues#managed-queues)) when async work is needed. `queue.connection` now passes for sync in production when `preflight.queue.expect_worker` is unset or false, instead of warning by default.

The names `queue.worker`, `expect_worker` and `PREFLIGHT_QUEUE_EXPECT_WORKER` stay unchanged. “A worker” here means whatever drains the queue, normally the managed queue. **Sync until needed, then Laravel Cloud managed queues, is the blessed path and passes.** Cloud sets `QUEUE_CONNECTION=cloud` and configures that connection with `driver=cloud`. When the default connection's resolved driver is `cloud`, `queue.worker` passes with “Managed by the platform (Laravel Cloud managed queue); no worker to check.” It reads no backlog and makes no network call. A custom connection name with that driver also passes; a Cloud environment marker or connection name alone never establishes platform management.

Publish `config/preflight.php` with `php artisan vendor:publish --tag=standards-preflight-config`. Both queue checks read `preflight.queue.expect_worker`, parsed from `PREFLIGHT_QUEUE_EXPECT_WORKER` as a nullable boolean:

| Setting | Sync queue | Cloud managed queue (`driver=cloud`) | Other async queue |
|---|---|---|---|
| Unset (`null`, default) | Pass; no worker needed. | Both pass; the platform provides the worker. | Connection passes; inspect backlog. |
| `false` | Pass; deliberately synchronous. | Connection warns about the contradictory expectation; worker passes as platform-managed. | Both checks warn that a queue is configured but no worker is expected. |
| `true` | Connection warns that a worker is expected but work runs synchronously; backlog check is not applicable. | Both pass; the platform provides the worker. | Connection passes; inspect backlog. |

`queue.worker` is advisory, never blocking even under `--strict`, and skipped with `--ci`. For database queues it reads the oldest unreserved job whose `available_at` is due, in the configured connection, table and queue. Age means time waiting since availability, so deliberately delayed jobs do not warn early. For Redis it reads the ready-list length and first payload atomically in one Lua call, using the configured connection and queue; the payload's Unix-seconds `createdAt` gives age when present. Delayed and reserved Redis sets are not inspected or migrated. Empty and fresh backlogs pass, but neither proves a worker exists.

`preflight.queue.max_backlog_minutes` defaults to 15 (`PREFLIGHT_QUEUE_MAX_BACKLOG_MINUTES`). Jobs waiting **longer than** that threshold warn. Unsupported drivers, missing Redis age, connection errors and timeouts report **Unknown** as a `warn` result, with a safe reason. The check makes at most one query or Redis call within `preflight.timeout` (default five seconds). Enable the hosting platform's managed queue (Laravel Cloud managed queues) for this connection, or set `QUEUE_CONNECTION=sync` deliberately for future jobs and review the existing backlog. Switching to sync or a new managed connection does not move or drain jobs already queued on the old connection.

The preflight runs **before migrate** and sees the previous release's queue. A backlog warning means “jobs are waiting and nothing is draining them”, not “this deploy broke something”. It is a signal to investigate: a slow or overloaded worker can also cause an old backlog. Heartbeats are deferred; no hosting API or worker-process inspection is performed.

**`queue.failed-store`: failed jobs live in two stores on a managed queue.** On a Laravel Cloud managed queue, failed jobs are kept by the platform and never reach the `failed_jobs` table. A post-deploy "0 failed jobs" check that reads only the database reports 0 while jobs fail.

- **When it applies:** the default connection's driver (or its name) is `cloud`, which creating a managed queue switches `QUEUE_CONNECTION` to, or a committed Cloud config (`cloud.yml`, `cloud.yaml`, `.cloud/`) names a managed queue.
- **What it says:** both stores and how to read each: `php artisan queue:failed` (the `failed_jobs` table) and `cloud managed-queue:failed-jobs <env> --json -n`.
- **Level:** advisory. It exits 0 on a deploy run, and 1 under `--strict` until the post-deploy check is declared: once your post-deploy check reads both stores, set `preflight.queue.failed_store_checked` (`PREFLIGHT_QUEUE_FAILED_STORE_CHECKED=true`) and it passes.
- **Other queues** (database, redis, sync) have one store and pass.

## Operator commands

An operator command is an artisan command that someone runs by hand to backfill, sweep or report: pattern `background.command`. `php artisan standards:make command <Name> [--backfill|--maintenance|--report]` writes `app/Console/Commands/<Name>Command.php` (artisan name `app:<name-in-kebab-case>`) and `tests/Feature/Console/<Name>CommandTest.php`, both stamped. `--backfill` is the default.

The command's work runs through `Tey\Standards\Operator\Operator`. The package's own classes stay final with no protected methods, so this is composition, not a base class, and it works in any Laravel app, with or without tey/kit:

```php
public function handle(): int
{
    return Operator::run($this, function (OperatorRun $run): void {
        $run->each($rows, function (object $row, OperatorRun $run): void {
            if ($row->processed_at !== null) {
                $run->skipped('already done');

                return;
            }
            $run->write(fn () => DB::table('visits')->where('id', $row->id)->whereNull('processed_at')->update(['processed_at' => now()]));
            $run->changed();
        });
    });
}
```

- **Dry run by default.** Without `--apply` the work runs and counts, and `write()` does nothing. The summary ends "Dry run: nothing was written".
- **Idempotent by contract.** The work skips what is already done. A re-run reports "Already done: nothing to change." with the skipped count.
- **Refused in production.** `--apply` in production needs `--force` and the command's name typed back at the prompt. A non-interactive run is always refused. A command can be allow-listed instead, in `standards.operator.allow` or `STANDARDS_OPERATOR_ALLOW` (comma-separated names). A dry run is read-only, so production allows it.
- **One at a time.** Each command takes a cache lock while it runs; a second run is refused. Without a cache store that supports locks, the command refuses rather than risk two runs at once.
- **A summary.** The summary table shows mode, processed, changed (or "would change"), skipped and failed, with each reason's count. The exit code is 1 when anything failed and 2 when refused.
- **No record data printed.** A reason is a short lowercase label, and anything else is refused. An item that throws counts as failed by its exception's class. The exception goes to the app's handler; its message is never printed.

`Operator::report()` is the read-only form, used by `--report`: the same lock, counts and summary (the column reads "Matched"), with no `--apply`, `--force` or production refusal.

The generated test proves the contract against a table it creates if it's missing: a dry run writes nothing, `--apply` is idempotent, and production refuses unless forced and confirmed. Edit the command's `TABLE`, `COLUMN` and its work for the real job; the test follows the constants.

**Rule `operator` (advisory).** A command under the source roots (extending `Illuminate\Console\Command`) that writes needs a dry run and a production guard. It passes if its work runs through `Operator::run`, or if it hand-rolls both halves: an `--apply` or `--dry-run` option, and an `isProduction()`, `environment('production')` or ConfirmableTrait `confirmToProceed()` check. A command the scheduler runs (`Schedule::command(Sweep::class)` or `Schedule::command('app:sweep …')` in `routes/console.php`, `$schedule->command(...)` in a Console Kernel or bootstrap's `withSchedule`) is a scheduled job, not an operator tool, and is not reported: an `--apply` gate and a production refusal would stop it in production. One that is also run by hand should still offer `--dry-run` for those runs. Writes are Eloquent and query-builder writes, `DB`/`Storage` writes, and file writes. Running writes through `Operator::report` is its own finding. The check is static, so a write made through another class is not seen, and a write-named call on something else (a cache `put`) is reported. An allowlist entry for an advisory acknowledges it (it isn't listed again), and goes **stale** once the advisory stops firing, in `standards:check` and the Pest suite alike, so it can't rot unnoticed. That's why `standards:check` lists its findings as advisories (`~`, and an `advisories` key in `--json`) that never fail the check, until an app adds `operator` to `Configuration::optionalRules` to enforce it.

## Integrations: inbound webhooks and API clients

**Inbound webhooks** (`integrations.webhook`). Run `composer require spatie/laravel-webhook-client` first, then `php artisan standards:make webhook <Provider>` writes a receiver:
- a signature validator: HMAC-SHA256 over `<timestamp>.<raw body>` with the secret from `<PROVIDER>_WEBHOOK_SECRET`, compared with `hash_equals`, with a ±300 s timestamp tolerance. A bad signature or an expired timestamp is a 403, and nothing is stored;
- a profile that stores every signed event that has an id and a type;
- `Process<Provider>Webhook`, whose `HANDLERS` map is the allow-list: an event with no handler is stored but not processed;
- an example handler, the config entry, the route and a test.

The first webhook also adds what all webhooks share:
- `App\Models\WebhookCall`, unique by (webhook name, event id), so a provider's retry of an event is answered 2xx and processed once;
- `App\Webhooks\ReceiveWebhook`;
- a migration adding `event_id` and `processed_at` to `webhook_calls`;
- `routes/webhooks.php`, loaded by `WebhookServiceProvider` outside the web group, with no session, cookies or CSRF, under a `webhooks` rate limit.

The handler runs in the transaction that marks the call processed, so a handler that throws rolls back and the queue retries it without a double effect. In a multitenant app the job is `NotTenantAware`, so a handler that needs a tenant finds it from the payload. Never log the body or the secret. Match the validator to the provider's documented scheme (its header names and what it signs).

**API clients** (`integrations.api-client`). `php artisan standards:make api-client <Provider> [--sdk|--http]` writes:
- the contract `App\Services\<Provider>\<Provider>Client`, which is what the app depends on;
- `App\Exceptions\<Provider>Exception`, carrying a status or class, never request data;
- the real client: the official SDK (`--sdk`; name its class in the provider), or Laravel's Http through a `<provider>` macro with base URL, token, timeout and retries with backoff (`--http`);
- `Fake<Provider>Client`, its provider, `services.<provider>` keys and `.env.example` lines;
- tests that bind the Fake under `Http::preventStrayRequests()`, so no test reaches the service.

**Rule `versioned-writes`.** A query-builder write on a versioned model must bump its version. A versioned (stale-write guarded) model bumps `version` through its model events, but a builder write skips them, so a stale guard silently passes.

- **What's checked:** `update()`, `upsert()`, `increment()`, `decrement()`, `incrementEach()` and `updateOrInsert()`, on `Model::where(…)` or `Model::query()`, straight on the model (`Model::upsert(…)`), or on `DB::table('<its table>')`.
- **What counts as a bump:** setting the version column (any value, `DB::raw('version + 1')` included), or incrementing the column itself. For `upsert`, the column goes in the update list, or, without one, in the values.
- **Not reported:** an Eloquent instance write (`Model::findOrFail($id)->update(…)`, `->first()->update(…)`), because it runs the events.
- **Migrations are skipped:** any file under a `migrations` directory (`database/migrations`, a module's `Database/Migrations`, kit migration stubs). Migrations are schema history: they may predate the version column, and they run once, outside the stale-guard flow, so a data backfill in one isn't checked.
- **What counts as versioned:** a model that uses a trait named `Versioned`, `HasVersion` or `OptimisticLock`, or one of `Configuration::$versionedModels` (traits, or classes a model is or extends). `$versionColumn` defaults to `version`. An app with no versioned model gets nothing.
- **Findings:** each gives the file, line and a fix. The rule is a static heuristic: a write reached through another class, or a builder held in a variable, isn't seen. A deliberate exception (a backfill, say) goes in the allowlist with a reason and an expiry.

**Rule `tenant-aware`.** In an app on spatie/laravel-multitenancy, work that touches tenant-scoped models runs inside a tenant, and only one pattern counts: the package's own. A job that runs outside any tenant does not fail; it silently finds nothing.

- **Commands:** an artisan command (extending `Illuminate\Console\Command`) that names a tenant-scoped model uses `Spatie\Multitenancy\Commands\Concerns\TenantAware`, with `{--tenant=*}` in its signature, so it runs once per tenant.
- **A command scoped to one tenant by an argument** is the accepted alternative. Its `handle()` resolves the tenant from `$this->argument(…)` or `$this->option(…)` (directly or through a variable read from one) with `X::findOrFail(…)`, a query ending in `->firstOrFail()` (or `sole()`), or `X::find(…)` / `->first()` followed by `if (! $t) { …; return / throw / abort / $this->fail() }` (also `=== null` or `is_null()`). Then all its work runs inside a closure passed to `$t->run(…)` or `$t->execute(…)`, any model method of that name, so an app wrapper such as `TalkWorkspace::find(…)` plus `->run(…)` counts; `X::findOrFail(…)->run(…)` chained also counts. "Work" is read conservatively: `handle()` may hold only the argument reads, the lookup, the null check, `$this->info/line/error/warn/comment/question/alert/newLine` lines whose arguments call nothing, the run/execute call and a constant `return`. Still reported: any other statement (DB or model work, before or after the callback), a tenant-model reference anywhere in the class outside the callback (a helper method called from inside it included), `find()` with no failing null check, a nullsafe `?->run`, a tenant from anything but an argument or option (`config()`, `first()` with no input), and a loop over tenants. Blind spots: the rule doesn't check that `run`/`execute` really make the tenant current, nor what the callback does beyond the existing arrow-fn dispatch check.
- **Queued jobs:** a job (`ShouldQueue`) that names one must be tenant-aware as the app's `config/multitenancy.php` decides.
  - It implements `Spatie\Multitenancy\Jobs\TenantAware`, or is listed in `tenant_aware_jobs`, or queues are tenant-aware by default (`queues_are_tenant_aware_by_default`, true unless set) and it isn't listed in `not_tenant_aware_jobs`.
  - A job that implements `NotTenantAware` and touches a tenant-scoped model is reported.
- **`model:prune`:** it runs outside any tenant, so a tenant-scoped `Prunable` or `MassPrunable` model must have `prunable()` bypass the scope (`withoutGlobalScopes()`). That marks it as landlord or cross-tenant housekeeping by design; otherwise it prunes nothing.
- **Dispatching inside `execute()`:** `$tenant->execute(fn () => dispatch(…))` or `fn () => Job::dispatch(…)` loses the tenant. A `PendingDispatch` queues when it is destroyed, which is after `execute()` has restored the previous tenant. Use a block closure (`function () { Job::dispatch(…); }`), or `Bus::dispatch($job)`.
- **Nothing else enters a tenant for this rule:** app-side boundaries, `$tenant->execute()` or `makeCurrent()` in a plain command are still reported, unless the command matches the argument-scoped shape above. The only exceptions:
  - a deliberate cross-tenant admin command, declared in the allowlist with a `reason` and an `expires`, so it stays visible and expires;
  - a landlord command, declared in `Configuration::$landlordCommands` with its reason (below);
  - a landlord prune whose `prunable()` bypasses the scope.
- **Landlord commands:** a command that runs before any tenant exists, such as one that creates the first owner or sends an invite, cannot run per tenant. Declare it, with the reason, in the trailing `landlordCommands` argument: `landlordCommands: [CreateOwnerCommand::class => 'Creates the first owner before any workspace exists.']`. A declared command is not required to use `TenantAware`. Nothing is skipped implicitly, and every declaration is checked:
  - an empty or blank reason is reported (`… is declared a landlord command without a reason`), and the command is still checked as tenant work;
  - a class that is not an artisan command in the source roots is reported (`landlord command … is not a command in the source roots`);
  - a declared command that also uses `TenantAware` is reported, since it cannot be both.

  The list lives in configuration rather than in an attribute on the command, so it works the same whether the app requires tey/standards in production or only in development, and a reviewer sees every landlord command in one place. Unlike an allowlist entry it has no expiry: a landlord command is landlord by design, not debt.
- **What counts as tenant-scoped:** `Configuration::$tenantModels`, when the app sets it, is the whole answer (traits a model uses, or classes it is or extends). Otherwise the rule detects:
  - Spatie's `UsesTenantConnection`;
  - a tenant-style trait (`BelongsToTenant`, `BelongsToWorkspace` (tey/kit's `Tey\Kit\Features\Workspaces\Concerns\BelongsToWorkspace`), `TenantScoped`, `WorkspaceScoped`, `ScopedToWorkspace` and the like). Kit's `HasWorkspaces` and `HasWorkspace` (the user model's memberships and roles), `IsKitWorkspace` and `GuardsStaleWrites` are excluded by name and never make a model tenant-scoped; a model using `HasWorkspaces` with a scoping trait is still scoped;
  - a global scope that names a tenant (`addGlobalScope` with a tenant scope class or a closure on a tenant key, or `#[ScopedBy]` a tenant scope);
  - a tenant key (`Configuration::$tenantKeys`, default `workspace_id` and `tenant_id`) in `$fillable`, or as a column of the model's table in `database/migrations`.
- **When it runs:** when the app requires spatie/laravel-multitenancy, tey/kit or tey/concierge, or sets `tenantModels`.
- **It never passes silently:** an active rule that finds no tenant-scoped model reports the advisory "tenant-aware checked nothing", because no command or job was checked. `standards:check` lists it (`~`); `standards:check --strict` fails on it, as on any advisory; the Pest test is skipped with the same reason.
- **Findings:** each gives the file and line (`path:line` in `standards:check`, `line` in `--json`) and a fix. The allowlist key stays target and check, so an entry survives edits to the file.

**Single-database, column-scoped apps.** An app whose tenants share one database and are told apart by a column (a `workspace_id` on each tenant table, with no `UsesTenantConnection`) is detected by default from that column: in `$fillable`, in its migrations, or in a tenant global scope or trait.

- If its tenant key has another name, set `tenantKeys: ['account_id']`.
- If detection still misses models (a key only in `$guarded`, a table made by raw SQL), name them: `tenantModels: [Note::class, Deck::class, ...]` or their shared trait or base class. That list then replaces detection entirely.
- Run `php artisan standards:check --strict` once after upgrading. A "checked nothing" advisory means the rule saw no tenant models and needs one of these settings.

`standards:make command` and `standards:make job` follow the same pattern in a multitenancy app.

- A command gets `use TenantAware;` and `{--tenant=*}`.
- A job gets `implements ShouldQueue, TenantAware`.
- With `--landlord`, a command gets neither, and a job implements `NotTenantAware` so it never waits for a tenant. Both carry a comment saying they're landlord-only and must not touch tenant-scoped models. A landlord command that must touch one (to create the first owner of a workspace, say) goes in `landlordCommands` with its reason.

**Rule `destructive-db` (opt-in).** Off by default; enable it by listing `destructive-db` in `Configuration::optionalRules`. It checks that a service provider's `boot()` (a class extending `Illuminate\Support\ServiceProvider` or named `*ServiceProvider`, under `app/Providers` or the source roots) calls `DB::prohibitDestructiveCommands(...)` with a guard that is true everywhere outside the testing environment. Why: `php artisan migrate:fresh --env=testing` with no `.env.testing` silently falls back to `.env`, and wipes the local database. The recommended snippet:

```php
// app/Providers/AppServiceProvider.php, in boot()
DB::prohibitDestructiveCommands(! $this->app->runningUnitTests() && ! config('database.allow_destructive'));

// config/database.php
'allow_destructive' => (bool) env('ALLOW_DESTRUCTIVE_DB', false),
```

For a deliberate local reset, set the flag for that one command: `ALLOW_DESTRUCTIVE_DB=1 php artisan migrate:fresh`. Never put it in `.env`: a command run against a missing `.env.<name>` falls back to `.env`, so a flag there switches the guard off for exactly the run it exists to stop.

- **Passes:** a negated testing check (`! $this->app->runningUnitTests()`, `! app()->runningUnitTests()`, `! $this->app->environment('testing')`, `app()->environment() !== 'testing'`), optionally joined with `&&` to negated config overrides (`! config('…')`, `! Config::get('…')`). `DB`, `\DB` and `Illuminate\Support\Facades\DB` are all recognised.
- **Fails:** no call in any provider; a bare call or `true` (it also prohibits in tests, where `RefreshDatabase` runs `migrate:fresh`); a production-only guard (`isProduction()`, `environment('production')`), which leaves local databases open; `env()` in the guard (app code reads config, never `env()`); any other guard, which the static check can't prove is true outside testing.

**Rule `integrations` (advisory).** A route whose URI mentions `webhook` must reach a handler that verifies its sender: spatie/laravel-webhook-client with a configured validator, Cashier's controller (or a subclass of it, which inherits the `VerifyWebhookSignature` middleware Cashier's constructor registers), or a controller that verifies a signature. One delegation is followed: an invokable controller that calls an injected dependency (a typed parameter or property, `$handler->handleWebhook($request)`) passes when that dependency's called method verifies, for example by wrapping `(new VerifyWebhookSignature)->handle(...)`, or when the dependency extends a verifying controller. A verifier further away isn't followed. And an `Http::` call belongs in a class that implements a contract; a service provider defining macros is exempt. Both checks read names, not behaviour, so they are reported as advisories (`~` in `standards:check`) until an app lists `integrations` in `Configuration::optionalRules`.

## Self-enforcing conformance

One command drives four layers: gates, agent guardrails, pattern lookup and a drift report. Nobody has to remember to run the checks.

### 1. Gates: `standards:check`

```sh
php artisan standards:check                 # every rule, exit 1 on drift
php artisan standards:check --changed       # findings in files changed against the base ref (printed; see below)
php artisan standards:check --file=app/Http/Controllers/InvoicesController.php
php artisan standards:check --json          # stable schema 1, see below
```

`--changed` compares against `--base`, else `STANDARDS_BASE_REF` (`standards.base_ref`), else the remote's default branch (`refs/remotes/origin/HEAD`, so a trunk named `live` is found), else `origin/main`, else `main`. It prints the ref it used on stderr, so `--json` stays parseable. `standards:check --strict` makes advisories fail too.

The gate reads `tests/Arch/configuration.php`, a file that returns the app's `Configuration` (change the path with `standards.configuration`; publish with `--tag=standards-config`). When the file is missing, `standards:install` scaffolds one inferred from composer.json and the app's folders: class roots, the Inertia pages folder, a typed ViewModel base, and whether tey/kit and tey/mod are present. With tey/mod, the layout in `config/mod.php` adds module controller, ViewModel and page roots (for example `app/Modules/*/Http/Controllers*`), and a controller found in a module folder outside them is generalised over its module name. It prints what it inferred, then adds `tests/Arch/ConformanceTest.php` requiring that file, unless a test already uses `Conformance::TESTS`. It never overwrites either file. Review the scaffold, since it is yours from then on. An app whose `ConformanceTest` builds its `Configuration` inline (the older pattern) has nothing for the gate to read, so `standards:check` fails closed and names the fix: `php artisan standards:install --extract` moves that `new Configuration(...)` into `tests/Arch/configuration.php` with the file's `use` imports and the top-level variables it reads, then rewrites the test to `require` it, so the Pest suite and the gate share one Configuration. It refuses, with the reason and nothing written, when the file already exists, when there are two inline Configurations, or when the expression reads `$this` or a variable it can't carry. `--dry-run` shows the two files and writes nothing, and `--json` prints the plan. A plain `standards:install --dry-run` dry-runs the whole install: it lists every file it would write (`created` or `modified`; unchanged files are left out) and writes nothing, and `--json` prints `{dry_run, installed, files}`. The gate runs every `Conformance::TESTS` rule outside Pest, including placement and the Pest presets, and reports the same findings the Pest suite does for the same app. Disabled optional UI rules and rule 51 (until `ciEnforce`) are listed under `skipped`, never reported as passes. Drift means any of these:

- an unexcepted violation;
- a stale allowlist entry;
- an allowlist entry without `expires`. Every entry needs `rule`, `target`, `check`, `reason`, `date` and `expires`, all real `Y-m-d` dates. The Pest ratchet reports the same entries;
- an expired entry;
- a rule that cannot run, such as a missing configuration, missing dev tools or a crash. The gate fails closed;
- a class that doesn't load (rule `load`). Before any rule runs, a separate PHP process loads every class in the class roots, so a fatal error (a missing abstract method, an unknown parent) becomes a finding with exit 1 instead of a crash. The other rules still run and skip that class.

Entries expiring within `standards.expiring_days` (14) are listed but don't fail. `--changed` and `--file` keep findings whose target is one of those files. Class targets map to files through `classRoots`. Expiry findings are always reported.

**CI.** Call the reusable workflow and make its job a required check:

```yaml
jobs:
  standards:
    uses: teylabs/standards/.github/workflows/standards.yml@v0.11.0
    with:
      base-ref: ${{ github.base_ref }}   # omit for a full check on main
```

Optional inputs: `php-version` (default `'8.4'`), `base-ref` (default empty, a full check), `runs-on` (runner label, default `ubuntu-latest`) and `timeout-minutes` (default `15`). GitHub does not allow `timeout-minutes` on a job that calls a reusable workflow, so pass both through `with:`; rule 51 reads them there (see [CI triggers](#ci-triggers)).

**Stable contract: the check name.** The workflow's only job keeps the id `standards` and has no `name:` key, so GitHub reports it as `<caller job name> / standards`; with a caller job named `Standards`, the required check reads `Standards / standards`. A release never renames that job or gives it a `name:`, so a branch-protection rule set on it keeps matching (a test fails if it changes).

It uploads `standards-report.json` as the `standards-report` artifact. **Pre-push.** `standards:install` installs a pre-push hook running `standards:check --changed`, in `.git/hooks` or in `core.hooksPath` when that points inside the repository. When `core.hooksPath` points outside it, it writes nothing and prints the line to add there. That includes a hooks folder set **globally** (`git config --global core.hooksPath ~/.git-hooks`): the setting applies to every repository, so no `.git/hooks/pre-push` would ever run and installing one would be a false comfort. `git config --show-origin core.hooksPath` shows where it comes from. Add the printed line to that folder's `pre-push`, or unset the global setting to use `.git/hooks`. It never replaces a hook it didn't write. Add the line to yours instead.

### 2. Agent guardrails: `standards:install`

`php artisan standards:install` writes, and on re-run refreshes, only what it owns:

- **AGENTS.md:** the pattern index between `<!-- tey/standards:patterns:start -->` and `:end` markers. It leads with the one always-loaded line, "Before implementing a page, action, MCP tool, setting… call bakery.find (offline: `php artisan standards:pattern`)", then "Traverse before you build". Text outside the markers is kept; an unmatched marker stops the install.
- **Skills:** one per recipe (leaves that aren't gaps) in `.ai/skills/standards-*` for Boost (`boost:install` / `boost:update` publishes them). The same file goes in `.claude/skills/standards-*` with `paths:` globs, so it loads when an agent edits a matching folder.
- **`.claude/settings.json` hooks:** merged into existing settings. Other keys and hooks are kept, and an invalid file is refused, never overwritten. Every hook first checks for `vendor/tey/standards/bin/standards` and exits 0 without it. It also does nothing unless the directory has `artisan` and a `composer.json` that requires `tey/standards`, so fleet and house-rules repositories are never blocked.

| Hook | Event | Effect |
| ---- | ----- | ------ |
| `stop` | Stop | Blocks finishing on `standards:check --changed` drift, with fix hints. Skipped when a Stop hook is already active. |
| `pre-commit` | PreToolUse `Bash` | Blocks `git commit` (exit 2) on drift, with fix hints. A check that crashes also blocks. |
| `pre-write` | PreToolUse `Write` | For a **new** file under `app/` or `resources/js/`, adds the path lookup and points at bakery.find, with the CLI as fallback. The lookup lists candidate leaves, their make command and the next question. If the content has no `standards-pattern: <leaf-id>` or `standards-pattern: gap` stamp, it denies once per session and file, asking for a leaf or `gap`. The retry goes through. |
| `post-edit` | PostToolUse `Edit\|MultiEdit\|Write` | Runs `standards:check --file=<path>` and returns findings as context. Never blocks. |

Hard blocks happen only at commit and Stop.

### 3. Pattern lookup: feature → unit → component

A hosted recipe MCP (`bakery.find`, `bakery.recipe`, `bakery.scaffold`, `bakery.check`) is the entry point for agents. This package ships the library it calls, and the offline CLI. Both answer with [pattern contract 1](docs/pattern-contract.md), whose stable fields are frozen by a schema snapshot test.

- **Features** (`resources/patterns/features/*.yaml`) compose unit leaves into a plan. Each is `{id, summary, keywords, questions?, composition: [{unit_leaf_id, role, when?}]}`. Seven ship: manage records, workspace settings, personal preferences, an MCP-exposed operation, document send, operator maintenance and file import.
- **Units** form a decision tree. It starts at "What are you building?" and branches into `ui-page`, `data-actions`, `mcp`, `settings`, `mail-notifications`, `background`, `access-tenancy` and `integrations` (`resources/patterns/<branch>.yaml`).
  - A branch is `{id, question, why?, options: [{answer, next}]}`, with 2–5 options and a "None of these" exit.
  - A leaf is `{id, recipe, summary, kit, components?, make?, recipe_make?, checks, paths, keywords, status, evidence?, rules?, related?, graduate?}`, where `status` is `ready`, `draft`, `gap` or `byo`.
  - A `draft` leaf without a generator carries its conventions as `rules`, the leaves it composes or sits beside as `related` (each must be another leaf), and `graduate`: when it becomes a generator or kit recipe. The lookup returns them (`conventions`, `related`, `graduate`), and the generated skill lists them.
- **Components** list the `@kit/ui` components a leaf is built from, as `{name, kit_component, use, props_hint}`. `resources/patterns/kit-exports.json` snapshots the kit's `@kit/ui` barrel, and the validator rejects any name it doesn't export.

**Build-your-own recipes.** Some pages and access patterns exist in only one app so far. They are `draft` leaves, with rules but no generator yet, and each says when it graduates:

| Leaf | For | Built from | Graduates |
| --- | --- | --- | --- |
| `ui-page.external-sync` | Connect an outside source, sync on demand, show the last run, replace behind a typed confirmation | `background.job`, `ui-page.external-link` | to a kit recipe, when a second app needs it |
| `ui-page.live-console` | Run a live session: a stage plus side panels that act on it in real time | `ui-page.workbench` (a workbench edits records; a console runs a session) | to a kit LiveConsole layout |
| `ui-page.stage-display` | A full-screen output another screen drives (projector, kiosk TV) | `ui-page.kiosk` | to a kit recipe |
| `ui-page.audience-live` | Guests join a live session on their phone and interact | `ui-page.stage-display` | to a kit recipe |
| `ui-page.remote` and `access-tenancy.device-pairing` | A second device acts for a signed-in user (a phone remote, a paired screen) | `ui-page.signed-guest`, `access-tenancy.signed-link` | to `standards:make pairing` |
| `access-tenancy.membership.scoped-guest` | Invite someone to act on one record, with no other access | `access-tenancy.membership.workspace` | to a Concierge feature (invitation scope) |

`php artisan standards:pattern "sync with an external source"` (or bakery.find) resolves to `ui-page.external-sync`, with its rules and related leaves.

`php bin/standards patterns:compile` validates the sources into `catalog.json`, and the suite fails if the two differ. The validator rejects these:

- unresolved `next` ids;
- orphans, nodes with two parents, and cycles;
- a question with no reachable `byo` exit;
- unknown fields, checks, units, flavors or kit components;
- a feature unit that isn't a leaf, and a `when` that names no question;
- a ready leaf without `make`.

A test runs every ready leaf's `make` in a fixture app and asserts that `standards:check` passes.

```sh
php artisan standards:pattern "manage records with bulk actions"   # a feature plan, else unit candidates + a question
php artisan standards:pattern resources/js/pages/settings/A/Page.vue # by path
php artisan standards:pattern --answer=4 --answer=2                  # walk the tree from the root
php artisan standards:pattern --recipe=ui-page.index                 # one leaf, with its kit components
php artisan standards:pattern --gaps                                 # gap leaves by demand: fleet apps × changes + past apps × changes
```

Every path ends in a leaf. Gap and byo leaves return their nearest recipes, the general conventions (rules and notes) and a note to proceed. A gap says "No recipe yet. Build to the nearest sibling `<id>` and flag it." A traversal or search that ends on one appends an anonymised demand record to `storage/standards/gap-demand.jsonl`: answers, catalog-keyword criteria, unit type and timestamp, with no code, names or paths. With `butter/bakery` installed, `recipe_make` becomes the preferred, labelled make command. Callers depend on `PatternCatalog` and the contract, not on the file format. Leaf `evidence` is counts only (`apps`, `count`, `prs`, optional `past_apps` and `past_count`). App names and change references never ship.

**Generators.** `php artisan standards:make <unit> <Name> --flavor=<flavor>` is the offline, deterministic generator, and `bakery.scaffold` returns its command. Every file it writes carries the leaf's pattern stamp, and it never overwrites. When several leaves share a make command, `--leaf=<id>` picks the stamp; the lookup's `preferred_make` includes it. A generated test that lands outside the folders `tests/Pest.php` covers with `->in()` binds the app's TestCase itself (`pest()->extend(...)`), so `$this->get()` and the container work. There are two paths:

- **Delegated to tey/mod.** `model`, `policy`, `job` and `notification` (flavor `plain`) run tey/mod's `make:*` generator, then stamp each PHP file it created. Without tey/mod they stop with an install hint.
- **Recipe stubs.** Units with no mod generator use the package's stubs. Point `standards.make.stubs` at your own copies to override them.

| Unit | Flavors | Writes |
| ---- | ------- | ------ |
| `page` | `index`, `show`, `form` | controller, `Page.vue` built from `@kit/ui` exports, `Route::controller()` group, Pest test; `form` adds a FormRequest and an Action |
| `capability-tool` | `read`, `write` | tey/kit `CapabilityTool` with `#[Capability]`, `rules()` and `call(Principal, int, array $validated)`, matching the kit's abstract methods, plus a Pest test. A downstream scaffold mirrors `--flavor=write` and has a drift test, so any change to this generator's output shape is announced in the CHANGELOG under Changed. |
| `settings-section` | `form`, `toggles` | settings controller, FormRequest, Action, `pages/settings/<Name>/Page.vue`, routes, test |
| `action` | `plain` | Action class and test |
| `workbench` | `page` | a tey/kit Workbench page in a workspace (needs tey/kit 0.4.20+): provider (capability, permissions, routes, navigation, `stale_write` as 409), policy, page and order controllers, the order FormRequest, a principal-first `Reorder<Name>Workbench` Action guarded by a version, a stand-in item Query to replace with your model, a `WorkbenchViewModel`, `pages/<Names>/Workbench/Page.vue` with one inspector, and a feature test |

**Generators fit the host.** Every `standards:make` unit reads the host before it writes anything:

- **Placement:** in an app whose tey/mod layout places files by a dimension (a `modules` layout, or one extending it), files go where tey/mod places them in the module named by `--module=<Name>`, through `Mod::current()->place()`.
  - Controllers, requests, policies, providers, models, actions, queries, view models, commands, enums and factories use the layout's own file types.
  - A folder the layout has no type for (`Mcp/Tools`, `Services`, `Webhooks`) goes under the module's root.
  - Tests go under the layout's test folder, keeping their subfolder. Pages go in the module's pages, rendered as `<Module>::<name>`. Route files and migrations go under the module.
  - Every class name, namespace, page render name and route-file path in the generated files (and in what they append to `bootstrap/providers.php` and routes) follows. The module's route files load through the app's `Mod::routes()`, as tey/mod expects.
  - Without `--module` such an app is refused, with the dimension named.
  - An app on a layout with no dimension (`laravel`), or without tey/mod, gets exactly what it got before.
- **Names:** a name that already ends in the unit's suffix is the base name, so `standards:make workbench SlideWorkbench` writes `SlideWorkbench*` files, not `SlideWorkbenchWorkbench*`. Likewise `BillingSettings` and `ReadPostsTool`.
- **Frontend imports:** pages import the kit's components from `@kit/ui` only when the host's tsconfig or Vite config aliases it; otherwise from the kit's own barrel, `@/components/Kit`.
- **tey/kit features:** a unit is refused, naming the command that adds what is missing, when the host has not adopted a tey/kit feature its code uses:
  - crud and workbench need workspaces and access;
  - a tenant model needs workspaces;
  - a capability tool needs mcp;
  - pages and settings sections need core.

  Adoption is read from `kit.features`, else the app's installed lineages. A workbench is also refused while the host's own copy of the kit components (`resources/js/components/Kit`) predates the Workbench, with the `kit:update` command.
- **`--dry-run` and `--json`:** `--dry-run` lists every file the unit would write (path, kind, `created` or `modified`) and writes nothing; `--json` prints the same list as JSON (`{dry_run, files}`), as tey/mod's `mod:*` generators do. A unit tey/mod generates gets `--dry-run`, and `--in` for the module, passed through.

**A modular app's configuration.** Name the module roots in `tests/Arch/configuration.php`: for example `app/Modules/*/Http/Controllers` in `controllerRoots`, `app/Modules/*/routes` in `routeRoots`, the view-model mount in `viewModelRoots`, `app/Modules/*/resources/js/pages` in `pageRoots` (with `app` in `pageScaffolds`), and the module namespaces in `presetIgnores`. Pest's stock Laravel preset expects `App\Models`, `App\Http\Requests` and the like, so module code is left out of that preset only; rule 20 and the tey/mod layout still place it.

**Helper or feature roots (`app/Helpers/*`, say).** The same setting covers a layout that keeps each feature's models, form requests and controllers under its own folder (`app/Helpers/Nighthub/Models`, `…/Http/Requests`, `…/Http/Controllers`): add `App\Helpers` to `presetIgnores`, and name `app/Helpers/*/Http/Controllers` in `controllerRoots` (plus any `placements`). Pest's Laravel preset otherwise flags every such class three times (extends `Model` outside `App\Models`, `FormRequest` outside `App\Http\Requests`, a `Controller` suffix outside `App\Http\Controllers`). Two things to know:
- **It's the whole preset.** The namespace leaves the stock Laravel preset entirely, not just those three expectations. Placement inside a helper (a model outside its `Models` folder) is then rule 20's job, through `controllerRoots` and `placements`, not the preset's. The `security` and `php` presets still run over it.
- **Old allowlist entries go stale.** Per-class allowlist entries recorded for this layout ("Helper layout") turn stale once the namespace is ignored, and stale entries fail. Delete them in the same change.

**Kit exports.** Validation reads only the shipped snapshot, `resources/patterns/kit-exports.json`, so CI is deterministic. When an app has tey/kit installed, `standards:pattern` and `standards:check` compare its `@kit/ui` barrel with the snapshot. An installed kit that lacks snapshot components prints a warning (recipes may name what it lacks). A kit that only adds components (a superset) prints an advisory, `the pattern catalog's kit snapshot is older than the installed tey/kit`, since nothing is missing. Neither fails. A weekly workflow (`.github/workflows/kit-snapshot.yml`) opens one issue when the latest tey/kit release's barrel differs from the snapshot, so the refresh ships before apps see the lag. To regenerate the snapshot from a kit checkout, run `php bin/standards kit-exports --refresh --from=../kit`, then `php bin/standards patterns:compile`.

### 4. Drift report

`standards:check --json` emits schema 1 with these keys:

- `app` (the Composer name) and `sha`;
- `generated_at`, `scope` (`full`, `changed` or `file`) and `paths`;
- `violations`: `[{rule, kind, target, check, hint}]`, where `kind` is `violation`, `stale`, `missing-expiry`, `expired` or `error`;
- `allowlist`: `{count, expiring, expired}`;
- `skipped`;
- `gaps`: `{catalog, evidenced, flagged, demand}`. `flagged` counts files stamped `standards-pattern: gap`, and `demand` is `{total, by_unit_type}` from the gap-demand log;
- `exit_code`.

`vendor/bin/standards report <reports or dirs> [--previous=last.json] [--json] [--strict]` aggregates several apps' reports. For each app it counts violations, allowlisted entries, expiring and expired entries, flagged gaps and gap-demand lookups, and lists regressions and fixes against the previous aggregate. Totals cover them all. `--strict` exits 1 on any regression. Delivery stays outside the package. A nightly fleet job can run each app's reusable workflow, or `standards:check --json` in each checkout, then collect the files and run `standards report reports/ --previous=state/last.json --json > state/today.json`. It posts the text form wherever the fleet reads, and keeps `today.json` as the next run's `--previous`.

**Coverage and limits.** These are static checks and generated scaffolds. The hooks rely on Claude Code's hook contract. Other agents get AGENTS.md and the Boost skills only. `--changed` needs a fetched base ref. Without a merge base it checks working-tree changes and says so. The package's suite requires the real tey/kit (^0.4.4) and laravel/mcp as dev dependencies, so generated kit classes are proven against the kit's actual signatures. The generators don't run `mod:*` commands. They emit from recipe stubs that an app or kit can override.

### 5. Kit adoption: `standards:adoption`

`php artisan standards:adoption` reports which package surfaces the app adopted, hand-rolled, is missing or doesn't need, and which of its own components and libraries no surface covers. It reads each package's adoption surfaces file, format 1 (the JSON Schema is tey/kit's `resources/adoption/surfaces.schema.json`): every installed `vendor/*/*/resources/adoption/surfaces.json`, plus any `--inventory=<path>` (repeatable). `--inventory` exists to add packages the app doesn't install (a calibration scan); an applicable surface there reads `hand-rolled` when one of its signals fires, and `missing` (`<package> is not installed`) otherwise. For a package the app does install (in `vendor/` or required in composer.json), `--ci` and `--compliance` fail closed with exit 2, so a gate always reads the installed package's own file. The plain report uses the explicit file instead, warns (`vendor inventory for <package> overridden by <path>`) and lists it under `overridden`. Two explicit files for one package fail. The scan is static. It reads the allowlist from the same configuration as `standards:check` (`standards.configuration`), and it executes no app code.

**Modes and exit codes.**

| Mode | Exits 1 when | Exit 0 otherwise |
| --- | --- | --- |
| (none) | never: prints the report | always (2 on an error) |
| `--ci` | any hand-rolled or missing row, or gap, without a valid exemption; any expired, undated or stale adoption exemption | a missing surface whose `status` is `new` only warns while the installed package is the release in its `since` (or its version is unknown) |
| `--compliance` | any hand-rolled, missing, exempt or gap row, including `new` surfaces and exempt gaps (zero exemptions) | every row adopted or n/a, no gaps, no adoption allowlist entries |

Exit 2 means it fails closed: an unreadable or invalid inventory, an unknown format, field, group, signal kind, fact analysis or condition, a `new` surface without `since`, a `rule` signal naming no standards rule (or used to compute a fact), an invalid allowlist entry (no reason, bad dates), a missing configuration, or an app file it can't read. With `--ci` or `--compliance`, the output ends with every blocking item in a stable order: rows (by package, then group, then file order), then gaps (components by path, then libraries), then problem exemptions. Each line gives the kind (`hand-rolled`, `missing`, `exempt` or `gap`), the package and surface id (or the gap's component or `npm:<package>`) and its `file:line` evidence. A missing row gives what the surface `expects` (its `uses`), and an exempt one cites the allowlist entry's `file:line`.

**Statuses and precedence.** For each surface, in this order:

1. `n/a`: its `applies` condition is false for the app's facts.
2. `hand-rolled`: any `handRolled` signal fires in an app-owned file, whether or not the package or feature is installed, and even when `uses` also match. A signal is evidence on its own, so a legacy app with no tey/kit still shows what it built by hand. The row lists both (`signals`, `matches`), and `note` still says when the package or feature isn't installed. One exception, per file and only for `file-name` signals: a file whose name matches but which itself satisfies the surface's `uses` (it imports from kit's aliases or renders one of `uses.components`, imports one of `uses.imports`, or, for app PHP, references one of `uses.classes`) yields. That file is adoption evidence (`matches`), not a signal. With no other hit left and a `uses` match, the row reads `adopted`; other files that still hit keep it `hand-rolled`, with only them as evidence. Every other signal kind (`template-tag`, `class-reference`, `rule`, `mail-view`, ...) still wins over `uses` in the same file.
3. `missing`: the package, or the surface's `feature`, isn't installed (`core` ships with the package). tey/kit's `shell` counts as installed exactly when ui.kit-shell reads it adopted, so `session-ui` (which carries it) counts too. Another feature counts as installed when `config/<package name>.php` sets `features` to a literal list naming it, or, with no such list, when a tey/mod lineage record names `<composer>:<feature>`.
4. `adopted`: any `uses` entry matches in an app-owned file. `imports` (`"<alias>:Name"`) match a named import from the alias or a deep `<alias>/**` module, or a default import of `<alias>/**/Name.vue`; `@kit/ui` and `@/components/Kit` are the same module. `components` are PascalCase component names matched case-sensitively in `.vue` templates: the tag itself (`<Button>`), `<Kit.Button>` (or `<NS.Button>` after `import * as NS` from kit), a local name the file's kit import binds to the component (`import { Button as KButton } from '@/components/Kit'` makes `<KButton>` count, as does a default import of `@/components/Kit/**/Button.vue`), or the kebab-case form of such a kit-imported name (`<data-table>` after `import { DataTable } from '@kit/ui'`). A lowercase native element (`<button>`, in any case) is never adoption evidence, only hand-rolled evidence through a `template-tag` signal; a kebab-case tag no kit import resolves, a name the file imports from a non-kit module, and a non-PascalCase entry never match. `classes` are references to the FQCN in app PHP (the `class-reference` matcher: written out, or through a `use` import, its short name or alias), and `config` keys are found in order in `config/<file>.php`. `files` count by presence, whoever owns them, because the package installs them.
5. `hand-rolled`, when nothing above matched but an app component, library or composer package maps to the surface through `replaces`.
6. `missing` otherwise.

**Module frontends.** Adoption reads every tey/mod module's frontend root as well as the app's: tey/mod's `modules` layout declares a module's pages and components at `@module/resources/js/pages` and `@module/resources/js/components`, with `@module` defaulting to `app/Modules/{module}`, so `app/Modules/*/resources/js` (and the `app-modules/*` and `modules/*` layouts' equivalents) join the frontend roots whether or not `frontendRoots` names them. A `frontendRoots` entry may also be a glob, expanded to the directories it names. Every frontend reading covers them: `uses.imports`, `uses.components`, the `vue-root`, `file-name`, `import` and `template-tag` signals, gap candidates, and the `pages/` folder test (a module's `resources/js/pages/**` is a page). Inside a module root a path glob also matches the module-relative path, so `resources/js/**/*Search*.vue` matches `app/Modules/Billing/resources/js/components/InvoiceSearch.vue`, and a `uses.files` path under `resources/js/` also counts at the same place in a module. Module Blade views are not read yet.

`exempt` replaces a hand-rolled or missing status when a valid allowlist entry covers it. Kit-owned files never count as adoption or as a hand-rolled signal: `resources/js/components/Kit/**` and lineage-recorded files whose bytes are unchanged (KitOwnership). An edited copy is the app's own.

**Without Laravel: `bin/standards adoption`.** `vendor/bin/standards adoption --base=<dir> --inventory=<file> [--json] [--ci|--compliance]` runs the same scan on a tree with no Laravel app (a package's adoption fixtures in its CI, say): `--base` (default the current directory) and `--inventory` also take a separate value (`--base <dir>`), and a relative `--inventory` is relative to where it runs. It reads the tree's `tests/Arch/configuration.php` when there is one, else the default configuration (frontend under `resources/js`, pages under any `pages/` directory there). Apps whose standards configuration (`tests/Arch/configuration.php`) uses Laravel helpers such as `config()` run the artisan command `standards:adoption`; the bare CLI is for fixtures and bare trees, and exits 2 if the configuration can't be evaluated without Laravel. It exits before scanning, in every mode, with the error as the message: on stderr as `standards adoption failed closed: Target class [config] does not exist.`, or with `--json` as `{"format": 1, "mode": ..., "error": "Target class [config] does not exist.", "exit_code": 2}` on stdout. It reads no database, so `kit_adoptions` is never consulted. Exit codes match the artisan command: 0 for a report, 1 for blocking rows under `--ci` or `--compliance`, and 2 for usage, an unreadable inventory or a schema error. As there, `--ci` and `--compliance` refuse an `--inventory` for a package the tree requires (exit 2), so a fixture tree that requires tey/kit is checked with the plain report. `--json` is the format-1 report, and each row also carries `surface` (its id), `facts` (each fact its `applies` names, with its value) and `row` (its status): the fields of a package fixture's `expected.json` case, so the package can diff against them.

**Fixture CI on older PHP or Laravel.** tey/standards needs PHP 8.4.1+ and Illuminate 13, so a package that supports older PHP or Laravel can't `require-dev` it. Such a package runs its adoption fixtures from an isolated tools project instead: `tools/standards/composer.json` requires only `tey/standards` (`{"require": {"tey/standards": "^0.11"}}`) and keeps its own `composer.lock`, committed or not as you prefer (committed pins the scan; uncommitted takes the newest release each run). It never touches the package's own dependency graph. Install it with `composer install --working-dir=tools/standards`, then run `tools/standards/vendor/bin/standards adoption --base <fixture> --inventory resources/adoption/surfaces.json --json` per fixture and diff the result against that fixture's `expected.json`. This job passes rule 51 (the package suite runs `bin/standards ci` over this exact workflow; for a distributed package, its Windows leg stays in the main test workflow, since `windows-gap` is judged across all workflows). It assumes fixtures under `tests/fixtures/adoption/<case>/` and an `expected.json` holding each row's `surface`, `facts` and `row`; adjust the `jq` projection to your file's shape.

```yaml
name: adoption fixtures

on:
  push:
    branches: [main]
  pull_request:
    types: [opened, synchronize, reopened, ready_for_review]

concurrency:
  group: ${{ github.workflow }}-${{ github.event.pull_request.number || github.ref }}-${{ github.event.pull_request.draft }}
  cancel-in-progress: ${{ github.event_name == 'pull_request' }}

permissions:
  contents: read

jobs:
  adoption-fixtures:
    if: github.event_name != 'pull_request' || github.event.pull_request.draft == false
    runs-on: ubuntu-latest
    timeout-minutes: 10
    steps:
      - uses: actions/checkout@v5
        with:
          persist-credentials: false

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          coverage: none

      - id: composer-cache
        run: echo "dir=$(composer config cache-files-dir --working-dir=tools/standards)" >> "$GITHUB_OUTPUT"

      - uses: actions/cache@v4
        with:
          path: ${{ steps.composer-cache.outputs.dir }}
          key: standards-tools-${{ hashFiles('tools/standards/composer.lock') }}
          restore-keys: standards-tools-

      - run: composer install --working-dir=tools/standards --no-interaction --no-progress

      - name: Diff every adoption fixture against its expected.json
        run: |
          status=0
          for fixture in tests/fixtures/adoption/*/; do
            tools/standards/vendor/bin/standards adoption --base "$fixture" --inventory resources/adoption/surfaces.json --json \
              | jq -S '[.rows[] | {surface, facts, row}]' > /tmp/actual.json
            if ! diff -u <(jq -S . "$fixture/expected.json") /tmp/actual.json; then
              echo "::error::adoption fixture $fixture differs from its expected.json"
              status=1
            fi
          done
          exit $status
```

There is no PHAR yet: the CLI still needs PHP 8.4 and the Illuminate 13 components it loads, so a PHAR would only repackage what the isolated tools project already installs, plus a signed release artifact to maintain.

**Signals.** `rule`: that standards rule's findings (`ui.kit-shell` today, through Conformance) mean hand-rolled. `vue-root`: an app `.vue` file whose first template element is one of `tags`; with `requires`, at least `min` (default 1) of its `routes` (a quoted route name or path ending in it) and `components` (tag-name globs, matched against a kebab-case tag's PascalCase form too, so `<primary-button>` matches `*Button`) must appear, and either list may be absent. `file-name`: globs on the app-relative path (or the basename, for a glob without `/`) of frontend files and Blade views. A path glob matches case-insensitively, so `resources/js/pages/Auth/**` also matches the Jetstream/Breeze `resources/js/Pages/Auth/Login.vue` (and `settings/` matches `Settings/`); a basename glob keeps its case, because it names a component. An optional `except` list of globs, read the same way (a path glob case-insensitively, a basename glob with its case), removes a file an include glob matched: `{"globs": ["*Card.vue"], "except": ["**/Components/AuthenticationCard.vue"]}` reads every `*Card.vue` except that one. A non-list, empty or non-string `except` is a schema error (exit 2). `import`: a module or `pkg/**` import, and `pkg#Named` needs that named import (`@/components/Kit#Name` also matches `@kit/ui` and deep kit modules). `template-tag`: in `.vue` pages by default, at least `min` tags that pass its optional `where` and `except` filters; an `<input type="hidden">` (any quoting or case) never counts, since CSRF and method-spoofing fields are not hand-rolled inputs. `where` and `except` each map a static attribute name to a non-empty list of literal values: `{"where": {"type": ["file"]}}` counts only tags whose every named attribute is static with a listed value, and `{"except": {"type": ["hidden", "search", "file"]}}` drops a tag when any named static attribute has a listed value. A bound attribute (`:type`, `v-bind:type`) or a missing one matches neither: it never satisfies `where` and is never dropped by `except`. Values compare case-insensitively, a bare attribute reads as `""`, and both compose with `min` (counted after filtering) and `scope`. Listing `hidden` in `except` repeats the built-in drop and counts nothing twice. An optional `in` names where it reads: `["vue"]` (the default, so an inventory without it means what it always meant), `["blade"]` or both. `in: ["blade"]` reads Blade views (see below) for HTML tags and anonymous or class components, `<x-name>`, `<x-dir.name>` and namespace-qualified `<x-dir::name>`; a signal's tag matches as written (`x-button` matches `<x-button>`, `input` matches `<input>`, an HTML tag in any case), and a PascalCase name never matches a Blade component. On Blade, `where` and `except` read static attributes only: a bound attribute (`:type`, `x-bind:type`), a value holding an echo (`type="{{ $type }}"`), a directive (`@class(...)`, `@style(...)`) and an attribute-bag echo (`{{ $attributes }}`, `{{ $attributes->merge([...]) }}`) are bound or unknown, so they satisfy no `where` and trigger no `except`. `{{-- --}}` comments, `@verbatim` blocks, HTML comments, `<?php ?>` and `@php` blocks and echoes are not markup. A non-list, empty, duplicated or unknown `in` value is a schema error (exit 2). An unknown key, an empty or non-object filter, a non-list value, an empty list, a non-string literal or a bound attribute name (`:type`) is a schema error (exit 2). `mail-view`: a Blade view under a `mail`/`emails` folder, or one using `mail::` components, that contains none of `without` (an empty list matches every mail view). `php-class`: every criterion given must hold (`extends` is the direct parent's FQCN, `implements` any one interface, `names` short-name globs). `class-reference`: app PHP that references any of `classes` (FQCNs, a leading `\` optional), at least `min` (default 1) times in one file, with an optional `scope` and an optional `paths` list of globs that narrows which app PHP files are read (file-name's glob rules: a glob with `/` matches the path case-insensitively, a basename glob keeps its case; `vendor/` and kit-owned files stay out whatever it says); it is valid in a fact's `computed.signals` and in `handRolled`, and replaces the earlier `php-call` idea. It uses the same matcher as `uses.classes`: the FQCN written out anywhere (`new \Mpdf\Mpdf(...)`, `X::method()`, `X::class`, a type hint, a string literal), and, after a `use` import of the class or of a namespace above it, its short name or alias (`use Barryvdh\DomPDF\Facade\Pdf;` then `Pdf::loadView(...)`). `->Name`, `::Name` and `$Name` are not references, comments are ignored, and Laravel facade aliases in config/app.php are not followed: list the facade's FQCN. A `use` import with no other reference in the file counts as one reference, as it does for `uses.classes`; otherwise only the references count, so `use X;` plus one `X::make()` is one reference. Kit-owned files (the kit tree and unedited lineage copies) and `vendor/` never count. An unknown key, a missing, empty or non-list `classes`, an entry that is not a string FQCN, a non-list, empty or non-string `paths`, or an invalid `min` or `scope` is a schema error (exit 2). `composer`: a `require` or `require-dev` package. `migration`: a table an app migration creates with `Schema::create` (never `Schema::table`). A table an installed tey/mod lineage record adopts or creates is adopted, not hand-rolled. That record is the primary, static evidence: `.mod-maintenance/installed/*.json` (and the legacy `.mod-maintenance/installed.json`), the records `kit:status` reads, `{format: 1, owner: "tey/mod:installed", artifact: {name: "tey/kit:<feature>", files, metadata: {adopt?: {stamp, tables}}}}`. Its tables are `metadata.adopt.tables` (`permission` stands for spatie/laravel-permission's five default table names) plus the tables its recorded migration files create, and the record also marks the feature installed. A record that can't be read, isn't JSON, or has a malformed `artifact` name or `metadata.adopt` is treated as absent, and the row's `note` says `lineage record treated as absent: <path> (<why>)`. tey/kit's `kit_adoptions` only adds to it: a table recorded there is adopted too, and the command reads that table from the app's default database when it exists, so the result never depends on which database the scan sees for a lineage-recorded table. A `composer` signal skips a package that the installed package itself requires (its composer.lock entry, else `vendor/<package>/composer.json`) when that surface's feature lineage is installed, and says so in `note`; without both, it still fires. When the table exists but can't be read, it warns, every created table reads hand-rolled, and each such row's blocking line carries the cause `adoption records unreadable (kit_adoptions)` (`cause` in `--json`). Storybook is never the app's UI: a `*.stories.*` file, anything under `.storybook/`, and a lowercase `stories/` folder outside `pages/` are left out of every signal, fact and gap scan. UI signals take `scope`: `public` is a path whose segment names a public, guest or focus surface (Auth, Login, Guest, Public, Marketing, Landing, Welcome, Focus, Docs, Legal, Help, Error, Print), and `signed-in` is everything else. Blade template-tag hits and app/Filament class-reference hits do not read their path; they read `signed-in`, `public` or `unknown` as below. `unknown` matches only `scope: any` (or a signal without `scope`), never `signed-in` or `public`.

**Blade, Livewire and Filament.** These screens are read as hand-rolled evidence, never as adoption: kit is Vue, so a Blade, Livewire or Filament screen reads hand-rolled until it is migrated to an Inertia page built with kit. What is read: `template-tag` with `in: ["blade"]` reads every Blade view under `resources/views` except mail and PDF templates (a `mail`, `mails`, `email`, `emails`, `pdf` or `pdfs` folder at any depth), published package views (`resources/views/vendor/`) and error pages (`resources/views/errors/`); those are out of scope by path, not exempted. Livewire views (`resources/views/livewire/**`) and Livewire Volt single-file components are ordinary Blade views here: a Volt component's markup is read and its `<?php ?>` class block is not. Gitignored files, `public/build`, Storybook and kit-owned files stay out as everywhere. Livewire PHP (`app/Livewire/**`) and Filament PHP (`app/Filament/**`) are app PHP, read through `class-reference` with `paths` (for example `{"kind": "class-reference", "classes": ["Filament\\Tables\\Table"], "paths": ["app/Filament/**"]}`); there is no separate signal kind. `file-name` already reads every Blade path, these excluded folders included, with its path scope. Scope rules: a Blade view reads `signed-in` when a route that passes the `signed-in-pages` reading renders it, and `public` when a route that passes the `public-pages` reading (top-level, no middleware, not an auth screen's URI) does. A route renders a view through `Route::view('<uri>', '<view>')`, a literal `view('<view>')` or `View::make('<view>')` in its closure, its controller action (`[Name::class, 'method']`, or `__invoke` for an invokable `Name::class`), or a Livewire full-page component (`Route::get('<uri>', Name::class)`), or a literal `Volt::route('<uri>', '<name>')`; the class is found by its short name, and only when exactly one app file has it. A Livewire component's literal `render()` view wins; a `render()` that is not a literal `view('<view>')` reads `unknown`. With no `render()`, the view follows Livewire's own naming (`ComponentRegistry`/`Finder` and `HandleComponents::getView`): the class name relative to `class_namespace`, each segment kebab-cased (`Str::kebab`), under `view_path`, so `App\Livewire\Billing\InvoiceList` renders `resources/views/livewire/billing/invoice-list.blade.php`. Livewire 3 and 4 default to `App\Livewire` and drop a trailing `Index` segment (`App\Livewire\Reports\Index` is `livewire/reports`); Livewire 2 defaults to `App\Http\Livewire`, keeps `index`, and always renders under `resources/views/livewire` (`view('livewire.<name>')`). The major comes from composer.json's `livewire/livewire` constraint when it names exactly one major; otherwise (no direct constraint, as with an app that gets Livewire through `livewire/flux` or `livewire/volt`, or an ambiguous one) from the `livewire/livewire` version composer.lock locks in `packages` or `packages-dev` (`v3.6.4` reads 3). It must be exactly one of 2, 3 or 4; `class_namespace` and `view_path` are read from `config/livewire.php` when they are a quoted string and `resource_path('...')`/`base_path('...')`. The convention reads `unknown` when the major is missing or ambiguous (`^2.0|^3.0`) and no composer.lock entry settles it (no lock, no `livewire/livewire` entry, or a version with no numeric major such as `dev-main`), a config value is not literal, the class is outside the namespace, a `Livewire::component()` alias names it, or two app classes share its short name. A `Volt::route` view is the first `<folder>/<name>.blade.php` that exists across the folders `Volt::mount()` names in `app/Providers/VoltServiceProvider.php` (an empty mount is Volt's `resources/views/livewire` then `resources/views/pages`); without that provider or with a mount that is not literal it reads `unknown`. A Livewire full-page route and a `Volt::route` also count as rendering a page for `signed-in-pages` and `public-pages`, with the same middleware rules as `view()`, whether or not their view resolves. Only the routed view file itself gets the route's scope: its layout (`@extends`, `<x-layouts.app>`), `@include` partials and components are not followed, and read `unknown`, as does any view no route reaches. A view rendered by both a signed-in and a public route reads both. An `app/Filament/**` hit reads `signed-in` when every panel provider (`app/Providers/Filament/*PanelProvider.php`, or an `App\...\*PanelProvider::class` listed in `bootstrap/providers.php`: the same discovery `signed-in-pages` uses) that discovers its folder (`discoverResources`, `discoverPages`, `discoverWidgets` or `discoverClusters` with a literal `app_path('...')`) calls `->login()` or passes a non-empty literal array to `->authMiddleware([...])`; a lone provider that discovers nothing claims every app/Filament file. Otherwise it reads `unknown`. Livewire PHP keeps its path scope. `uses.components` reads `.vue` files only and `uses.classes` skips `app/Filament/**` and `app/Livewire/**` (kit is Vue-only, so a Livewire class is never adoption evidence), so no Blade, Livewire or Filament file ever makes a surface adopted.

**Facts.** Each fact in an inventory declares how it is computed. `{"signals": [...]}` is true when any of its signals fires in an app-owned file (not `rule`). An app that already adopted the kit component still "has the need", because a kit import counts as a signal. `{"analysis": "<name>"}` names one of standards' own analyses. Kit-installed files and routes count for analyses; vendor code never does. A "route file" is any PHP file under `routes/`, under the configuration's `routeRoots` (module globs such as `app/Modules/*/routes`, as rule 55 reads them), or under a module layout read even without a configuration: `app/Modules/*/routes`, `app-modules/*/routes` and `modules/*/routes`. A route group whose callback `require`s or `include`s another route file, or a `->group(<file>)` call, applies its middleware and prefix to the included file's routes, along every chain of includes (a loop is cut off). Only a literal path is followed: `__DIR__.'/x.php'`, `base_path('x.php')` or a quoted string. A non-literal one (a variable, `config()`, string building) is not guessed at: its routes are not read, and the report warns `<file>:<line> includes a route file standards cannot resolve (...)`. Each analysis is true only on evidence:

| Analysis | True when |
| --- | --- |
| `signed-in-pages` | a route statement or group behind the auth middleware (`'auth'`, `'auth:<guard>'`, `Authenticate::class`) renders a page: `Inertia::render`, `inertia()`, `view()`, `Route::inertia`/`Route::view`, or an app controller (`Name::class`) whose class file renders one. A file included from an auth group counts when it renders a page anywhere. `routes/api.php`, an `api` middleware group and an `api/` prefix (also inherited through an include) never count. A Filament panel provider (`app/Providers/Filament/*PanelProvider.php`, or an `App\...\*PanelProvider::class` listed in `bootstrap/providers.php`) whose panel calls `->login()` or passes a non-empty literal array to `->authMiddleware([...])` also counts, with or without routes; one with neither, or with a non-literal `authMiddleware()`, does not |
| `public-pages` | a route file has a top-level `Route::get`/`view`/`inertia` outside any group, with no middleware in its statement and not an auth screen's URI, in a file no middleware group includes; or a page path names Public, Marketing, Landing, Welcome, Legal or Docs. A Filament panel without `->login()` or auth middleware is never counted as a public page |
| `auth-routes` | composer.json requires laravel/fortify, breeze, ui or jetstream, or a route file names a login, forgot-password, `password.request`/`password.reset`, `verification.notice` or `email/verify` route |
| `workspace-model` | an app class (under `app`, `src`, `modules`, `app-modules` or the configured roots) is named Workspace, Team, Organisation or Organization, or a migration creates such a table |
| `mail-notifications` | an app class extends `Illuminate\Mail\Mailable`, or defines `toMail()` |
| `mcp-server` | composer.json requires laravel/mcp, `routes/ai.php` exists, or a route file calls `Mcp::web()`/`Mcp::local()` |
| `settings-routes` | a route file has a URI containing `settings` |
| `search-route` | a route file has a URI or name containing `search` |
| `destructive-routes` | a route file registers `Route::delete`, a resource controller (`resource`, `apiResource`, `resources`) or `->delete('...')` |

**Gaps (direction 2).** An app-owned `.vue` component outside `pages/` is a gap candidate when it imports a third-party library (a bare npm module that isn't the app itself, an inventory's own npm package, `@kit/ui` or a framework runtime), or when its name is on the generic-name list. A domain-prefixed name such as `InvoiceTable` is a candidate only through an import. Each package.json dependency that isn't a runtime, build/type/lint/test tooling or a non-UI library is also a candidate. A candidate that matches any surface's `replaces` (`components` name globs, `npm` packages, or `pkg#Export` for an import) maps to that surface, and makes it hand-rolled unless it is otherwise adopted. A surface's `replaces` may also carry an optional `except` list of path globs, read like a `file-name` signal's `except` (a glob with `/` matches the app-relative path case-insensitively, a basename glob keeps its case): a component whose name matches that surface's `components` but whose path matches an `except` glob does not map to it, so `{"components": ["Button"], "except": ["resources/js/components/ui/**"]}` leaves an app's own `ui/button/Button.vue` substrate out while `forms/Button.vue` still maps. A generic-name candidate excluded that way and mapped by no other surface is not a gap either; `except` does not touch `npm` or `composer` mapping, nor a candidate's third-party import. A non-list, empty or non-string `except` is a schema error (exit 2). Anything else is a gap row. Component gaps are keyed by component name, case-insensitively: `BreadCrumbs.vue` and `Breadcrumbs.vue` (in any folders) are one gap, whose subject is the first-seen path and whose `files` and blocking evidence list every file. A composer package a surface `replaces` maps the same way, but an unmapped one is never a gap. `src/Adoption/Lists.php` holds the lists (versioned; the report carries `lists`):

- generic names: Button, Input\*, Select\*, Modal, Dialog, Dropdown\*, Table, DataTable, Tabs, Toast, Tooltip, Badge, Card, Avatar, Pagination, DatePicker, Combobox, Menu\*;
- framework runtimes: vue, @inertiajs/\*, @vueuse/\*;
- tooling: vite, @vitejs/\*, laravel-vite-plugin, laravel-mix\*, any `*-loader`, webpack\*, @babel/\*, @vue/compiler-sfc, vue-template-compiler, vue-eslint-parser, globals, cross-env, typescript, vue-tsc, @types/\*, eslint\*, prettier\*, postcss\*, autoprefixer, sass, Tailwind, Vitest, Playwright, husky, lint-staged and similar (a trailing `*` is a prefix, a leading `*` a suffix). List version 2 added the Laravel Mix / webpack era;
- non-UI (version 3): axios, ky, ofetch, uuid, nanoid, qs, query-string, crypto-js, js-cookie, pusher-js, laravel-echo, socket.io-client, ziggy-js, @inertiajs/\* (the framework itself), vue, vue-router, pinia, vuex, @vueuse/core, mitt, zod, yup, lodash and lodash-es. Neither a dependency nor a component import of one is a gap; a component that imports only these is a candidate solely by a generic name. Date libraries (dayjs, date-fns and the like) stay gaps, because a kit surface may cover date display.

**Exemptions.** These are allowlist entries in the same Configuration `allowlist`. For a surface the rule is `adoption.<composer>.<surface-id>` (for example `adoption.tey/kit.layout.page`); for a gap it is `adoption.gap` with the component path or `npm:<package>` as `target`. Each also needs `target`, `check`, `reason` and `date`, as every entry does, plus an `expires` date. An expired or undated entry exempts nothing and blocks `--ci`, and so does a stale one (its row is adopted or n/a, or its gap is gone). `standards:check` accepts `adoption.*` rules and still reports an entry without an expiry. n/a comes only from `applies`, never from an exemption.

**`--json`** (format 1): `format`, `app`, `generated_at`, `mode`, `lists` (the Lists version), `overridden` (`[{package, by, vendor}]`: explicit files that replaced an installed package's, plain report only), `inventories` (`[{package, npm, alias, origin: vendor|option, source, version, installed, surfaces}]`), `facts` (`{<composer>: {<id>: {value, evidence, computed}}}`), `adopted_tables`, `rows` (`[{package, id, layer, group, label, feature, installed, applies, status, underlying, new, since, note, cause, evidence, signals, matches, replaced, uses, docs, exemption, skipped}]`, where evidence items are `{file, line, detail}` and `underlying` is the status before an exemption; `skipped` is additive to format 1, see below), `gaps` (`[{subject, kind: component|library, file, line, detail, files, exemption}]`, `files` being every `{file, line, detail}` the gap covers), `exemptions` (`{invalid, stale}`), `warnings`, `summary` (a count per status, plus `gaps` and `exempt_gaps`), `blocking` (`[{kind, package, subject, evidence: [{file, line}], expects, cause, problem?}]` for the mode, or what `--compliance` would block for the plain report) and `exit_code`. An error prints `{format, mode, error, exit_code: 2}`.

**A `{kind: rule}` signal that can't run is skipped, not evidence.** Each row's `skipped` is `[{signal, kind: 'rule', rule, reason}]` (`signal` is the 0-based index in the surface's `handRolled`), empty when every signal ran. "Can't run" means exactly one thing: the standards process cannot load Pest, pest-plugin-arch or nikic/php-parser (`Conformance::missingDependencies()`, thrown as `Tey\Standards\RuleUnavailable`), as when `vendor/bin/standards adoption` runs from a package without Pest. The reason is that message verbatim: `Architecture checks require pestphp/pest ^4.7 || ^5.0, pestphp/pest-plugin-arch ^4.0 || ^5.0 and nikic/php-parser ^5.0 in require-dev.` The plain report keeps evaluating the surface's other signals, so they decide the row, marks it `skipped [n]` in the table with a footnote giving the rule and reason, and exits 0. `--ci` and `--compliance` fail closed with exit 2, naming the surface, the rule and the reason. A rule that runs and finds problems is evidence as before, and any other failure while running a rule (an unsupported Pest major, a rule error) still exits 2 in every mode.

**Blind spots.** It is static and conservative:

- Routes, classes and views that vendor packages register (kit's own session or settings routes, for example) are invisible to the analyses, so a fact may read false and its surface n/a.
- Route groups are found by brace depth only.
- A route file included through a non-literal path is not read (the report warns), so a fact only it would make true reads false.
- Routes a service provider registers (`loadRoutesFrom()`, `Route::middleware(...)->group(...)` in `boot()`, `withRouting(then:)`) are not read, nor are the files they load unless a route root holds them; and such a file is read on its own, without the provider's middleware or prefix.
- `php-class` checks the direct parent, not the ancestor chain.
- `class-reference` and `uses.classes` read text, not PHP's name resolution: a group import (`use A\{B, C}`), a class reached through the current namespace without an import, a facade alias, a string built at runtime and a container binding are not seen; a written-out FQCN counts even inside a string literal, and a `use` line not at the start of a line is not read as an import.
- Imports are static `import` statements, so dynamic `import()`, `require` and globals are not seen.
- A component registered globally and used without an import is seen only through its template tag.
- Scope comes from path names only, except for Blade template-tag hits (their routes) and app/Filament class-reference hits (their panel).
- Without the app's database, `kit_adoptions` reads as empty (with a warning and a `cause` when the table is there but unreadable).
- Blade, Livewire and Filament are read only where an inventory asks: a `template-tag` signal with `in: ["blade"]`, or a `class-reference` with `paths`. An inventory that lists neither reads such an app's UI surfaces n/a or missing, not hand-rolled.
- Alpine-only behaviour (`x-data`, `x-show`, a dropdown or modal built from Alpine directives on plain `<div>`s) leaves no tag to match.
- Blade scope stops at the routed view: a layout or partial reads `unknown`, so a `signed-in` or `public` signal misses markup that lives there; `@include`, `@extends` and `<x-layout>` are not followed to any depth.
- Dynamic components (`<x-dynamic-component :component="...">`) are read as the tag `x-dynamic-component`, never as the component they render.
- Filament resources, pages and widgets that a plugin ships in `vendor/` are not app files and are not read; nor is a panel's `->resources([...])` list, so a resource registered only that way reads `unknown`.
- Folio pages are not followed, so their hits read `unknown`; a Volt component reached only through `Livewire::component()`, `<livewire:...>` or `@livewire` reads `unknown` too.
- Livewire: a component that extends an app base class instead of `Livewire\Component` is not recognised; Livewire 4 single- and multi-file components, extra `component_namespaces`/class locations and `Livewire::addNamespace()` are not read, and a component rendered from another view (`<livewire:...>`) is not scoped. Livewire 1's `Route::livewire()` is not read.
- `signed-in-pages` follows a route's controller by its short class name to a file that renders a page anywhere in the class; it does not resolve the action method, and an auth route defined inside an outer `api` group is read on its own statement.
- A component with no third-party import and a domain name (not on the generic-name list) is never a gap candidate, so hand-rolled in-house components that import nothing are under-counted.

The pattern leaf `ui-page.kit-adoption` carries the recipes.

## Agent guidance

The package ships a Laravel Boost guideline. Select `tey/standards` during `php artisan boost:install` to include it in the application's agent instructions. `standards:install` adds the pattern index, per-recipe skills and Claude hooks described above.

*Changelog: 2026-10-12 — trial `tey/standards` package with configured checks, exact stale-checked ratchets, seeded negative controls and a package-source-only strict preset.*
*Changelog: 2026-10-13 — added offline deploy preflight, registered checks, CI mode, timeout guards and persistent emergency skips; mail warns at deploy time, while generic --strict promotes failed results but not advisories for CI. (#105)*
*Changelog: 2026-10-13 — prepared v0.1.0 for public release, with production-only preflight dependencies, optional development architecture tools, no-dev boot coverage and Boost guidance. (#105)*
*Changelog: 2026-10-13 — log every preflight result and summary, add automatic and explicit stderr reporting without changing JSON stdout, and document verification after deploy. (#123)*
*Changelog: 2026-10-13 — prepared 0.2.0 with sync until needed, then scale-to-zero managed queues; added driver-based advisory backlog evidence, nullable worker policy and deploy-timing guidance. Command-output regressions cover managed-queue remedies for policy conflicts, old backlog and unknown evidence. (#124)*
*Changelog: 2026-10-13 — prepared 0.3.0 with offline warning-level brand.favicon, documented current and historical defaults, layout overrides, extra hashes, CI coverage and strict failure promotion. (#130)*
*Changelog: 2026-10-13 — audited full upstream histories, published all nineteen defaults with reference-only SHA-1 values, and added warning-level ICO header/directory, PNG type/180×180 and SVG-root checks without GD. (#130)*
*Changelog: 2026-10-13 — prepared 0.4.0 with the shared rule-51 parser and architecture/preflight checks, distribution detection, Linux-only apps, ready-PR Windows package coverage, advisory cache/macOS/Unknown suggestions and measured job-size guidance; Windows package CI and dated PCNTL limits. (#134)*
*Changelog: 2026-10-13 — Cloud managed queues pass without backlog or network inspection; Windows command contracts use test-only signal doubles, leaving only real PCNTL-dependent tests skipped and retaining the production guard. (#145)*
*Changelog: 2026-10-13 — prepared 0.4.1 with advisory-by-default rule 51, shared opt-in enforcement, visible preflight failures and architecture skip counts; document run, fix or ratchet, then opt in. (#150)*
*Changelog: 2026-10-13 — prepared 0.5.0 with Pest 4.7+ and Pest 5 architecture support, stable targeted diagnostics, dual-major negative fixtures and a Pest 5 temp-app architecture check. (#147)*
*Changelog: 2026-10-13 — prepared 0.5.1 with the concurrency-draft requirement: separate draft and ready concurrency groups to keep draft-snapshot runs from cancelling the full suite. (#151)*

## Backend outcome checks

These opt-in architecture tests register automatically with `Conformance::TESTS`. All findings use exact `rule`, `target` and `check` ratchet entries; fixing an excepted finding makes its entry stale.

| Rule key    | Contract                                                                                                                                                                                                                                                                                                                                                                                                |
| ----------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `actions`   | Named classes in `sourceRoots` cannot live in `forbiddenNamespaces`. The default `['Operations']` matches an exact namespace segment. Use `['Operations', 'Services', 'App\\Legacy']` to ban more segments or a full namespace prefix. It does not require an Action suffix or a particular action library.                                                                                             |
| `toast`     | Outside `uiRoots`, outcome keys (`status`, `success`, `message`, `error`, `flash`) cannot use `->with()` or `Inertia::flash()`. `session()->flash()` and the Session facade's `flash()` are forbidden. Public controller `store`/`update`/`destroy` methods returning literal redirect chains must include `->toast*()`, unless the method flashes a kit 0.4.6+ headless toast first (`$this->toast(...)` from Toastable, or `Tey\Kit\Features\Core\Toast::success|info|warning|error`), outside any closure. Routed non-GET `__invoke` methods follow the same rule. An off-site redirect needs no toast, and neither does a guest form whose `back()` or literal `to_route('<name>')` (optionally `->with('<key>', ...)`) lands on a page whose `<FormStatus>` shows it (see below). |
| `mail`      | Host source cannot subclass Mailable or send through Mail facade methods (`to`, `send`, `raw`, `queue`, `later`, `cc`, `bcc`, `html`, `plain`). Use Notifications, including `Notification::route('mail', ...)` for ad-hoc recipients. `mailComposer: null` permits stock MailMessage. Set a fully qualified `mailComposer` to require that class in each `toMail()` body and reject `new MailMessage`. |
| `providers` | Literal entries in `bootstrap/providers.php` must autoload and must not belong to Composer's dev-only autoload namespaces or package directories. Lock `packages-dev` metadata and root `autoload-dev` are checked even when development dependencies are currently installed. No provider is instantiated and the bootstrap file is never executed.                                                    |

PHP checks resolve imports and aliases and ignore comments and strings. Toast analysis inspects direct return chains, ternaries and match arms, not variable assignments or redirects hidden in helpers. **Off-site redirects need no toast**, since no app page shows it: `Inertia::location(...)` is never treated as a redirect, and `redirect()->away($url)` or `Redirect::away($url)` passes only when `$url` is provably another host: an absolute http(s) literal whose host isn't the app's (`APP_URL` in `.env` or `.env.example`, or the literal `url` in `config/app.php`), a concatenation or interpolated string starting with such a literal up to the end of its host, or a variable the action assigns exactly once to one of those and passes to no other call. Everything else stays failing, by choice: a relative path, the app's own host, `url()`, `route()`, `config('app.url')`, a parameter, a property, a method result such as a checkout session's `->url`, or a variable assigned more than once. Those are the blind spot: an external URL the rule can't see is external still needs a toast (or an allowlist entry), and an app host not recorded in those files is treated as external. **A guest form confirmed by kit's `<FormStatus>` needs no toast** (kit 0.5.65: a persistent `role=status` confirmation). A `store`/`update`/`destroy` or routed `__invoke` return of `back()` or `redirect()->back()` (optionally `->withInput()`/`->withErrors()`) passes when all of this is provable: every route that registers the action is a literal `Route::post|put|patch|delete`, `match`, `any` or readable `resource` route with a literal URI; every middleware on it and its enclosing `->group()`s and `Route::group([...])` arrays is a literal `web`, `guest`, `signed`, `throttle:*` or `precognitive` (no `can()`); exactly one GET route has the same full URI (literal prefixes applied) and is either an action on the same controller or on another controller (so an invokable store controller with a separate page controller works), or `Route::inertia('<uri>', '<literal page>')`. The GET route's controller is resolved to its fully qualified class as Laravel's router does: `[X::class, 'm']` and `X::class` through the route file's `use` imports, aliases and namespace; a string action (`'X@m'`, an invokable `X::class`, or a method name in a `Route::controller(C::class)->group()`) gets the enclosing groups' literal `namespace` (`Route::namespace('...')->group()`, nested namespaces joined) unless it starts with `\` or that namespace. A `'X@m'` string is never resolved through `use` imports, since Laravel doesn't. That class must be declared exactly once under `controllerRoots`, or, when it is declared nowhere there, exactly once in the file composer's classmap or PSR-4 map gives it. A short name nothing imports, a non-literal group namespace, a `namespace()` on the route's own chain, an FQCN declared twice, or any mutating route whose controller can't be qualified (a variable action, for example) keeps the finding; every return of that GET action is `Inertia::render('<literal>')` or `inertia('<literal>')`, all one page; and that page is exactly one `<page>.vue` under `pageRoots` where that page, or a `.vue` file it imports up to three import levels below it (app- or kit-owned, through `@/` or relative specifiers, import cycles cut), has a script that imports `FormStatus` from `@/components/Kit` or `@/components/Kit/Form` and a template that renders `<FormStatus`. The walker is the one `ui.kit-shell` uses (`Checks\VueImports`). **A named flash passes under the same proof.** `back()->with('<key>', ...)` or `->with(['<key>' => ...])` with literal identifier keys passes, and so does the `toast` rule's `flash outcomes through Toastable` check for `status` and the other outcome keys, when every key is read by a `<FormStatus>` in that tree. Its `:message` binding (FormStatus's text prop in kit) may read it as `flash.<key>`; the Inertia v2 shared prop `$page.props.flash.<key>` or `usePage().props.flash.<key>`; the Inertia v3 page flash `$page.flash.<key>` or `usePage().flash.<key>`; `page.flash.<key>` or `page.props.flash.<key>` where the same file's script binds `const page = usePage()` (or `let`, any name); `?.` is allowed at every step. `page.value.flash` is not read, since Inertia's Vue `usePage()` returns a reactive object, not a ref. On the page itself, `<key>` / `props.<key>` counts when its `defineProps` declares `<key>`. kit FormStatus's `flash-key="<key>"` (or `flashKey`) prop counts as a static string, or bound to a string literal (`:flash-key="'<key>'"`); a bound variable does not. **`Inertia::flash` before a `back()` counts as that redirect's flash.** `Inertia::flash('<key>', ...)` or `Inertia::flash(['<key>' => ...])` with literal identifier keys, written as a top-level statement of the method before the statement holding the return, adds its keys to that `back()`'s, which then need a reading FormStatus as above. Only top-level statements count, since they run on every path to the return; an `Inertia::flash` inside an `if`, loop, `try` or another expression, or one with a non-literal key, makes the return unprovable and keeps the finding. An outcome key (`status` and the rest) through `Inertia::flash` is still a `flash outcomes through Toastable` finding; only `->with()` gets the FormStatus exemption there. **A literal named-route redirect lands on that route's page.** `to_route('<name>')`, `redirect()->route('<name>')` or `Redirect::route('<name>')`, with the name as its one and only argument (so no route parameters), passes under the same proof as `back()`, with the same flashed keys (`->with()` and top-level `Inertia::flash`), except that the page is the named route's instead of the GET on the form's own URI. The form's own route must still be guest as above. The name must resolve, across the same route files (`routeRoots`, module routes included), to exactly one registration: the full name joins every enclosing `->group()`'s literal `name('<prefix>')` / `as('<prefix>')` or `Route::group(['as' => '<prefix>', ...])`, outermost first, with the route's own `->name(...)` calls. `Route::match([...], ...)` registers the literal verbs it lists, `Route::any(...)` all of them, and `Route::view(...)` / `Route::redirect(...)` names are read too. `Route::resource('photos', C::class)` and `Route::apiResource(...)` register the names, verbs and URIs Laravel 13's `ResourceRegistrar` generates: `photos.index` (GET `photos`), `photos.create` (GET `photos/create`), `photos.store` (POST `photos`), `photos.show` (GET `photos/{photo}`), `photos.edit` (GET `photos/{photo}/edit`), `photos.update` (PUT/PATCH `photos/{photo}`) and `photos.destroy` (DELETE `photos/{photo}`); `apiResource` leaves out `create` and `edit`. Literal `->only(...)`, `->except(...)`, `->names(...)` (a base name or a per-action map), `->name('<action>', '<name>')`, `->parameters([...])` and `->parameter(...)` are honoured, the wildcard is the singular resource name with `-` as `_`, a nested `photos.comments` registers `photos/{photo}/comments` as `photos.comments.*`, and `admin/photos` registers `photos.*` under the `admin` prefix. That route must be a GET (or GET and HEAD) or `Route::inertia(...)` with a literal URI and no `{parameter}`, so a resource `index` or `create` can be a target and `show` can't, pass the same guest middleware checks, and render one literal page that reads every flashed key. A non-literal name, any second argument, an unknown name, two routes with the name, a POST or signed-in target, or any registration in those files whose name can't be read keeps the finding: a non-literal `->name($x)`, `as`, prefix or argument, a `Route::group([...])` key other than `as`, `prefix`, `middleware`, `namespace`, `controller` and `where`, a `resource` with an options array, `->shallow()`, `->scoped()`, `->middlewareFor()` or a builder other than `middleware()` before it, `resources`, `apiResources`, `singleton` and `fallback` registrations, a macro or any other unknown `Route::` call, or an app whose route files or providers call `Route::resourceVerbs()` / `resourceParameters()` or reference `ResourceRegistrar`. Routes registered from `vendor/` packages, through a `$router` variable, or by a provider's `loadRoutesFrom()` aren't seen at all; the last keeps the whole exemption off (below), the others are blind spots. The `flash outcomes through Toastable` check reads an array flash (`->with([...])`, `Inertia::flash([...])`) key by key, as if each were passed singly, and, when the chain is provably rooted in `redirect()`, `back()`, `to_route()`, `response()`, `Redirect::*` or `Inertia::*` (directly or through a variable every assignment in the method sets to one), fails closed on what it can't read that way: a non-literal array key, a spread, a lone non-array argument such as `->with($data)`, or a non-literal key in `->with($key, $value)`. On any other root (a model, a query builder, an unknown variable or method result) only literal outcome keys are flagged, so `Post::query()->with($relations)` and `->with($key, $callback)` pass; the blind spot is a redirect held in a property, a parameter or a helper's return, whose `->with($data)` is not flagged. `session()->flash(...)` and `Session::flash(...)` are flagged for any key; `$request->session()->flash(...)` and other session instances are not followed. A key that is flashed but never read, a non-literal or non-identifier key, a bare `<FormStatus />` for a named key (it reads the `status` page prop, which a flash reaches only through shared props this rule doesn't follow), a page prop named like the key on a child component, or a `with()` inside a closure keeps the finding. Anything else keeps the toast required, by choice: a signed-in or unknown middleware, a non-literal middleware or prefix, a `Route::group([...])` array with a key other than those above, a `resources`, `singleton` or unreadable `resource` registration naming the controller, a route file `require`d inside any group, a controller that declares or calls `middleware()`, a redirect other than `back()` or a literal named-route redirect, a page name that is a variable, two possible pages, or a missing page. The app's own setup must keep the route guest too: `bootstrap/app.php` must exist and parse, and every call on its `withMiddleware` configurator must either add nothing to a group (`redirectGuestsTo`, `validateCsrfTokens`, `trustProxies`, `api`, `remove` and the like) or add only literal classes on `GuestFormStatus::SAFE_MIDDLEWARE` (any `\\HandleInertiaRequests`, kit's `Tey\\Kit\\Features\\SessionUi\\Http\\Middleware\\PrepareKitRequest`, Laravel's `AddLinkHeadersForPreloadedAssets`), whether to `web`, to a named group or globally (`append`, `prepend`, `appendToGroup`, `prependToGroup`, `group`, `use`, `web(append:, prepend:, replace:)`). A non-literal or unknown entry, an unknown configurator call, the configurator passed anywhere else, an `alias` over a guest middleware name, or `withRouting(then:)` / `withRouting(using:)` keeps the toast required. So does any provider under `app/Providers` or in `bootstrap/providers.php` that calls `group()`, `routes()` or `loadRoutesFrom()`, includes a file, calls `Route::middleware` or `pushMiddlewareToGroup` / `prependMiddlewareToGroup` / `appendMiddlewareToGroup` / `middlewareGroup` with anything off the allowlist, or aliases a guest middleware name. **Middleware the app declares guest-safe counts as guest.** `Configuration::$guestSafeMiddleware` is a list of `['middleware' => ..., 'reason' => ...]` entries, each a declared fact that this middleware signs no one in, with the reason it doesn't: for example `['middleware' => App\Http\Middleware\EnterRouteWorkspace::class, 'reason' => 'resolves the route workspace; signs no one in']` or `['middleware' => 'feature:*', 'reason' => 'a feature-flag gate that 404s when off']`. A declared class counts wherever `SAFE_MIDDLEWARE` does (`bootstrap/app.php` group and global appends, provider group pushes) and on a route's `->middleware(...)` as `X::class`; a declared alias string counts on a route like the guest names. `feature:early-access-intake` matches exactly that string, `feature:*` matches `feature:` with any parameters, and bare `feature` matches only the bare alias. The facts are checked, and a bad entry is a `toast` finding that declares nothing: a missing, empty or non-string reason; a missing, empty or non-string middleware; an auth alias (`auth`, `auth.basic`, `auth.session`, `verified`, `password.confirm` or `can`, with any parameters); an alias that `bootstrap/app.php`'s `alias()` or an app provider's `aliasMiddleware()` maps to a class failing the class checks; or a class that is one of Laravel's auth middleware, extends `Illuminate\Auth\Middleware\Authenticate` (or another of them) through app or vendor parents read statically (source roots, then composer's classmap and PSR-4 maps), names one anywhere in its body, or can't be found or parsed. What the class does otherwise is not proven: the reason is the app's word for it. Blind spots: providers in `vendor/` and providers outside `app/Providers` not listed in `bootstrap/providers.php` are not read, nor is middleware added from a controller's parent class or at runtime; `back()` is assumed to return to the form's own GET page (a form posted from another page is not followed), and the page tree is read only for an import, a `<FormStatus` tag and its `:message` text, not for whether it shows on every outcome, whether `flash` in a child is really the page's flash, or whether a `v-if` hides it. The walker doesn't follow dynamic imports, glob imports, other aliases, `.ts` re-export modules or globally registered components, and stops below three levels. **A Laravel Fortify guest flow's bound response gets the same proof** (`Checks\FortifyFormStatus`). Fortify registers its routes from `vendor/`, so they are never in `routeRoots`, and its own default responses are never flagged (no rule scans `vendor/`). An app class bound to one of Fortify's success response contracts has its `toResponse()` read like an action: its return is `back()` or a literal named-route redirect, with its `->with()` keys plus the top-level `Inertia::flash` keys before it. Its `->with()` outcome keys pass the `flash outcomes through Toastable` check when all of this is provable: (1) exactly one registration of the contract across the app providers (`app/Providers` and `bootstrap/providers.php`), and it is the literal `$this->app->singleton|bind|instance(<Contract>::class, <AppClass>::class)`; (2) `config/fortify.php` exists, parses and returns a literal array whose `prefix` is a literal string (absent: `''`), `middleware` a literal list (absent: `['web']`), `guard` a literal string (absent: `web`), `views` literal `true` (absent: true), `paths` absent or a literal string map, and whose literal `features` list holds the flow's feature call; (3) every `fortify.middleware` entry is guest middleware (the route names above, or declared in `guestSafeMiddleware`), and `bootstrap/app.php` and the providers keep the route guest as above (Fortify adds `guest:<guard>` itself); (4) no app provider calls `Fortify::ignoreRoutes()`, `viewPrefix()` or `viewNamespace()` or names a Fortify `*ViewResponse` contract, and no route in `routeRoots` has an unreadable URI or name or takes the URI or name of a Fortify route the proof relies on; (5) the landing page is registered by exactly one app-provider `Fortify::<view>(fn () => Inertia::render('<literal>', ...))` (or a closure whose only statement returns that), and its tree reads every flashed key as above. The flows: `SuccessfulPasswordResetLinkRequestResponse` (POST `forgot-password`, `password.email`; `back()` lands on `requestPasswordResetLinkView`, the GET `forgot-password` page, `password.request`; needs `Features::resetPasswords()`), `PasswordResetResponse` (POST `reset-password`, `password.update`; `back()` lands on `resetPasswordView`, GET `reset-password/{token}`; needs `Features::resetPasswords()`), and `EmailVerificationNotificationSentResponse` (POST `email/verification-notification`, `verification.send`), which Fortify puts behind `auth:<guard>`: signed in, so never exempt. A named redirect may name only `login` (`loginView`) or `password.request` (`requestPasswordResetLinkView`, with `resetPasswords()` on); Fortify's default password-reset response redirects to `login`. Failure responses are never exempt. **kit's session-ui pages count.** With no app view registration, when the app adopted kit's `session-ui` (`config/kit.php` `features`, else an installed lineage record, as `ui.kit-shell` reads it), kit's own registration is read from `vendor/tey/kit/src/Features/SessionUi/SessionUiProvider.php`: it must be `Inertia::render('<literal>', new <ViewModel>(..., session('status'), ...))`, the response must flash `status` only, and the page's tree must render a bare kit `<FormStatus>` (imported from `@kit/ui` or `@/components/Kit`, with no `message` or `flash-key`), which shows the `status` page prop. kit's `Auth/ForgotPassword/Page` and `Auth/Login/Page` qualify; `Auth/ResetPassword/Page` renders no FormStatus. `toResponse()`'s body must be top-level `Inertia::flash` statements and then one return: the redirect itself, or Fortify's own shape `$request->wantsJson() ? new JsonResponse(...) : <redirect>` (or `! $request->wantsJson() ? <redirect> : new JsonResponse(...)`), where `$request` is its parameter; the JSON branch is ignored. Fortify 1.41's password-reset responses use only that ternary, so `expectsJson()`, an `if ($request->wantsJson()) { return ...; }` form, a `response()->json()` branch, any other condition or a second return keep the finding. Blind spots: an `Inertia::flash('status', ...)` stays a finding as for actions; a missing `config/fortify.php` is unprovable even where kit's or Fortify's defaults would apply; `fortify.domain`, `fortify.redirects` and `fortify.limiters` are not read; a Fortify contract bound through `app()`, a facade, a closure or a provider in `vendor/` makes the binding unprovable, and kit's provider is read only at that vendor path (not a published copy); any route in `routeRoots` with an unreadable URI or name (a `resource`, `match` or `any` registration, a `Route::group([...])` array) makes every Fortify flow unprovable; and `back()` is assumed to return to the flow's own form page. Route detection covers literal Route facade calls (including fluent calls), class constants and explicit invokable tuples/strings; dynamic registrations are not resolved. Composer mail checks are structural references, not proof of delivery or branding. A literal entry inside a `class_exists(X::class)` conditional is not flagged: an `if` around the `return`, a ternary entry (also spread as `...(cond ? [X::class] : [])`), or `array_filter([...], class_exists(...))` (also `'class_exists'` or a closure calling it). Unconditional dev providers still fail, and other filters or non-literal lists report a non-literal array. Provider checks use the active autoloader plus lock metadata; they do not replace an isolated no-dev boot test or prove arbitrary provider dependencies survive boot. Missing bootstrap provider files are inapplicable. Keep `composer.lock` available for dev-package classification.

## Frontend source checks

The `descriptors` rule rejects role/permission checks and boolean can-maps in frontend source and PHP response arrays. A role/permission check is auth-shaped:

- `hasRole(`, `hasPermission(` and the like;
- `can(`/`cannot(` as a bare call (`can('x')`, `$can(`) or on an auth-shaped receiver (`user`, `auth`, `$page.props.auth…`, `permissions`, `gate`, `policy`). A method on any other object, such as Tiptap's `editor.can().undo()` or a command `chain.can()`, is not one;
- a user's `.roles` or `permissions` lookups (`$page.props.auth…roles` included);
- an admin flag (`is_admin`, `isAdmin`);
- a `role` or `permission` compared against a known role name (`owner`, `admin`, `member`, `editor`, `viewer` and the like).

A template `role=` attribute, an ARIA role (`role: 'textbox'`, `el.role === 'group'`), a domain role (`FeedRole`, `node[role]`, `role === 'actor'`) and an identifier that merely contains "role" are not flagged. The `js-imports` rule checks bare package imports against dependencies, devDependencies and peerDependencies in `package.json`. **A package that comes with another one** (`@inertiajs/core`, which `@inertiajs/vue3` depends on) must be declared at the **same range** the parent asks for, or pinned with an npm `overrides` entry. A looser range (`^3`) can install a second copy, and two copies of the same types break vue-tsc. The finding's hint names the parent's range when `node_modules` is installed; check with `npm ls @inertiajs/core` (one version, no duplicates). Five UI checks are opt-in through `Configuration::optionalRules`: `ui plus`, `ui new-tab`, `ui dropzone`, `ui toast` and `ui header-actions` (so is the backend rule `destructive-db`, above). These are static source checks; runtime authorization and response contracts still need application tests.

| Rule          | Source contract                                                                                                                                                                                                                                                                                                                         |
| ------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `descriptors` | `.vue` scripts/template expressions and `.ts`/`.js` source contain no role/permission checks, admin flags or boolean can-maps. PHP literal arrays keyed `can` are flagged, including computed entries. Render server action descriptors instead.                                                                                        |
| `js-imports`  | Static imports (including side effects and types), re-exports and literal dynamic imports name declared dependencies, devDependencies or peerDependencies. Scoped subpaths map to their package. Relative imports, `node:` and configured alias prefixes are local. Missing package manifests produce a finding; malformed JSON throws. |
| `ui plus`     | Static Add/New/Create labels on configured action tags carry a configured plus icon, including self-closing tags with `label`/`icon` attributes.                                                                                                                                                                                        |
| `ui new-tab`  | Configured action tags with literal `target="_blank"` carry an exact `noopener` rel token and a configured external icon. A component the file imports from the kit (`@/components/Kit`, a path under it, or `@kit/ui`) adds `rel="noopener noreferrer"` itself, so it needs no rel, but still needs the icon. |
| `ui dropzone` | Native file inputs (including literal `:type` bindings) occur only in the configured owner file.                                                                                                                                                                                                                                        |
| `ui toast`    | Only the configured module imports the toast library; templates outside the notification centre do not access loose `flash.status`, `success`, `message`, `error` or `toast`; a `:message` / `v-bind:message` binding on a `<FormStatus>` the file imports from `@/components/Kit` (or `Kit/Form`) is not loose, whatever path it reads (`$page.flash`, `usePage().flash`, `page.flash`, `props.flash`), and a `flash-key` prop is never flash text.                                                                                                                                                           |
| `ui header-actions` | A page ViewModel (extending the kit's `PageViewModel` or `WorkbenchViewModel`) whose `actions()` or `pageActions()` returns a literal array of `Action` descriptors declares at most one `priority: 'primary'` and at most three primary or secondary actions, and does not pass more than three with no priority at all. A `<template #actions>` slot holds at most three literal action controls. Advisory: actions built in a loop or helper are not counted. |
| `styling.pure-tailwind` | Required. `.vue` files have no `<style>` block; `.vue` and Blade templates have no `style="..."` attribute; `:style` binds custom properties only; `.css` files hold only Tailwind directives, `@font-face` and `:root`/`.dark` tokens. See below. |
| `ui.kit-shell` | Required, active only once the app adopts tey/kit's `shell` feature. Signed-in pages render in kit's Workspace Layout: `app.ts`'s layout resolution does not return an app-owned layout, and no app-owned `.vue` file renders its own signed-in `<header>` or `<nav>`. See below. |

**Kit-owned files are not scanned** by `descriptors`, `ui plus`, `ui new-tab`, `ui dropzone`, `ui toast`, `ui header-actions`, `styling.pure-tailwind` or `ui.kit-shell`. tey/kit owns them and `kit:update` replaces them, so a finding there is not the app's to fix. A file is kit-owned when it is under `resources/js/components/Kit/`, or when tey/mod's installed lineage records (`.mod-maintenance/installed/*.json`, or the older `.mod-maintenance/installed.json`) list it among the files they generated, such as a kit page under `resources/js/pages/`, and its contents still equal the recorded bytes. Once the app edits a generated file it is the app's code and is checked again. A record not in tey/mod's installed format (format 1, owner `tey/mod:installed`) names nothing. `js-imports` still scans kit files.

**Rule `styling.pure-tailwind`.** The app styles with Tailwind utility classes and `@theme` tokens, not with its own CSS. It is required (not in `optionalRules`) and reads `.vue` files under the frontend roots, Blade views (`*.blade.php`) under `resources/views`, the source roots and the frontend roots, and `.css` files under `resources/css`, the source roots and the frontend roots. It fails on:

- a `<style>` block in a `.vue` file (`style block in a .vue file`);
- a `style="..."` attribute in a `.vue` or Blade template (`inline style attribute`);
- a `:style` or `v-bind:style` binding that is not an object literal of custom properties only (`:style binds a CSS property other than a custom property`): `:style="{ width: w }"`, `:style="styles"` and `:style="[a, b]"` all fail, since the rule cannot prove a variable holds only custom properties;
- in a `.css` file, any selector rule (`custom CSS rule .btn`), a declaration other than a `--token` on `:root` or `.dark`, a class rule inside `@layer` (an element rule there, such as `body { @apply bg-background; }`, passes), and any other at-rule, such as a loose `@keyframes` (`at-rule @keyframes outside @theme`).

Allowed: Tailwind's `@import`, `@theme`, `@source`, `@plugin`, `@utility` and `@layer` (plus `@config`, `@reference`, `@custom-variant`, `@tailwind` and `@charset`); `@font-face` rules and files; custom-property declarations on `:root` and `.dark`; `@media`, `@supports` and `@container` are read through with the same rules. Not scanned: kit-owned files (below), **Filament theme CSS** (a file under a `css/filament/` folder, such as `resources/css/filament/admin/theme.css`, or one that imports Filament's vendor theme), and published package views under `resources/views/vendor/` (mail templates need inline styles). HTML and Blade comments are ignored.

**The custom-property exemption.** To hand a server value to the styling, bind it as a CSS custom property and read it in a class: ``<div class="w-(--progress)" :style="{ '--progress': `${progress}%` }">``. Only quoted `'--name'` keys pass. A ternary (nested too) passes when every branch is such an object, `{}`, `undefined` or `null`: `:style="color ? { '--avatar-color': color } : undefined"`. A real property, a variable or a call in any branch still fails; `&&` and `||` forms are not read and fail. Blade has no binding, so there a `style` attribute passes when every declaration is a custom property: `style="--progress: {{ $progress }}%"`.

**Fix recipes.** Static styling: replace the CSS with Tailwind classes in the template (`style="color:red"` becomes `class="text-red-600"`). A design value used in many places: a token in `@theme` (`--color-brand: oklch(...)`, then `bg-brand`), or on `:root`/`.dark` for a themed value. A reusable compound: `@utility`. An animation: `--animate-*` with its `@keyframes` inside `@theme`. A dynamic value: a custom property, as above.

`php artisan standards:pattern "rule styling.pure-tailwind"` shows these recipes (pattern `ui-page.styling`).

**Fails closed.** A file the rule cannot read (`could not read the file`) or parse (`could not parse the file`: an unclosed comment, `<script>`, `<style>`, tag or `{`) is a finding, not a pass. Findings carry `file:line`; the allowlist key is target and check without the line, so an entry survives edits above it. A real exception goes in the allowlist with a `reason` and an `expires` date, as for any rule: an entry without a reason is rejected, and one without an expiry fails.

**Rule `ui.kit-shell`.** An app that adopted tey/kit's `shell` feature renders its signed-in pages in kit's Workspace Layout (`resources/js/pages/Workspace/Layout.vue`), not in a header, account menu or workspace switcher of its own. It is required (not in `optionalRules`) but reports nothing until the shell is adopted: `config/kit.php` sets `features` to a literal list naming `shell` (or `session-ui`, which carries it), or, when that key is absent or `null`, an installed tey/mod lineage record (format 1, owner `tey/mod:installed`) names `tey/kit:shell` or `tey/kit:session-ui`. A literal list without either means not adopted, whatever the records say, as in tey/kit. The rule reads files statically; it never boots the app. It fails on:

- **an app-owned layout in the layout resolution** (`signed-in pages resolve to an app-owned layout (<path>), not kit's Workspace Layout`, on `app.ts`): in `app.ts` or `app.js` at a frontend root, the `layout:` option of `createInertiaApp(...)`, and any `.layout =`, `??=` or `||=` assignment (a persistent layout default set in `resolve`). Each identifier in that expression that is a default or named import of an app `.vue` file (`@/` or a relative path) is a finding, unless the file reaches kit's Layout (it, or an app `.vue` file it imports, up to three levels deep, imports `pages/Workspace/Layout.vue` or `Layout` from `@kit/shell`). A `layout:` value that is a bare name is followed once into a local `function` or `const`. Strings and comments are not read, and in an array layout (`[Shell, Sublayout]`) only the first element is the shell;
- **an app-owned signed-in header** (`signed-in <header> outside kit's shell`, or `<nav>`): a `.vue` file under the frontend roots with a `<header>` or `<nav>` element whose markup (up to its matching close tag) holds at least one of: a sign-out (`logout`, `log out`, `sign out`, `signOut`), an account or user menu component (`<UserMenu>`, `<AccountMenu>`, `<ProfileDropdown>`, `<NavUser>`, `<user-menu>` and the like: a `User`, `Account` or `Profile` name ending in `Menu` or carrying a suffix after it, such as `<UserMenuContent>`, `<UserMenuTrigger>` or `<user-menu-content>`, or ending in `Dropdown`, `Nav` or `Navigation`; `<NavigationMenu>` and `<MenuButton>` are not account menus), or a workspace switcher component (`<WorkspaceSwitcher>`, `<TeamSwitcher>`, `<TenantPicker>` and the like). A `<header>` or `<nav>` alone, or with only page links, never fails. One finding per outermost such element.

Exempt: **public, guest and focus surfaces**: kit's PublicShell and GuestShell (anything under `pages/Workspace/Partials/`), and any app file whose path has a segment starting with `Auth`, `Login`, `Guest`, `Public`, `Marketing`, `Landing`, `Welcome`, `Focus`, `Docs`, `Legal`, `Help`, `Error` or `Print` (`layouts/GuestLayout.vue`, `components/PublicHeader.vue`, `layouts/auth/Layout.vue`; `Authenticated` does not count). **Kit-owned files** (above). The shell's own paths (`pages/Workspace/Layout.vue` and `pages/Workspace/Partials/`) when no lineage record lists them, since their owner is unknown; a recorded one that the app edited is checked. **Page-local sub-navs**: page components (`Page.vue`) are not scanned for headers, and a section or tab `<nav>` without a sign-out, account menu or switcher passes anywhere. **Wrappers**: a file that imports kit's Layout itself is not scanned for headers, so the `nav`, `user-menu` and other slot content it passes is sanctioned.

**Fix recipes.** Return kit's Layout from the layout callback for signed-in pages (`layout: (name) => name.startsWith('Public/') ? PublicShell : name.startsWith('Auth/') ? GuestShell : Layout`, with `import Layout from '@/pages/Workspace/Layout.vue'`), or an app wrapper that renders it. Delete the app's own header and move its parts into the Layout's slots: `mark` (the logo), `nav` and `drawer-nav` (an app's own module switcher, replacing PrimaryNav at every width), `bar` and `actions` (header controls), `extra` (account-menu items), `user-menu` and `workspace-switcher` (replacements with the same props), `footer`, and `widthClass`. The app's pages come from the server's navigation config (`Registry::navigation(...)`, slots `primary` and `user-menu`), not a hand-written nav. Replacing `UserMenu` or `WorkspaceSwitcher` app-wide goes through the `@kit/shell` alias. Where a hand-rolled shell has to stay for now, add an allowlist entry with `rule: 'ui.kit-shell'`, the finding's target and check, a `reason` and an `expires` date: an entry without a reason is rejected, and one without an expiry fails.

`php artisan standards:pattern "rule ui.kit-shell"` shows these recipes (pattern `ui-page.shell`).

**What `ui.kit-shell` cannot see.** It prefers missing a case to a false positive:

- a layout resolution it cannot read is not flagged: a `layout:` value imported from a `.ts` module or built by a call, a layout picked from `import.meta.glob` or another lookup, aliases other than `@/` (read as `resources/js/`), an entry other than `app.ts`/`app.js` at a frontend root, and a TSX or React entry;
- it does not know which branch of the callback serves signed-in pages: every app layout the expression can return counts, unless its path names a public, guest or focus surface, so a signed-in layout with such a name (a `FocusLayout` used for the whole app) is missed;
- per-page layouts (`defineOptions({ layout })`, a page's `layout` export) are not read; their headers are caught only by the header check;
- a header whose signals are outside the element (a sign-out wired from `<script>` only, a menu rendered through a slot or `<component :is>`), or that is built from components named other than above, is missed; a `Page.vue` that renders its own shell header is missed;
- only a `<header>` or `<nav>` element is read as the shell: a layout whose root `<div>` holds the account menu and sign-out without either element is missed;
- a file that imports kit's Layout is trusted entirely, even if it also renders a second header beside it;
- an app file placed under `pages/Workspace/Partials/`, or an unrecorded `pages/Workspace/Layout.vue`, is taken as kit's;
- Blade, React and Livewire shells are not read.

**Fails closed.** An `app.ts`/`app.js` entry or a `.vue` file under the frontend roots that the rule cannot read is a finding (`could not read the file`), not a pass, while the shell is adopted. An app layout it follows through imports and cannot read is not followed.

All options below are trailing named constructor arguments. Defaults preserve optional UI enforcement as off. Explicit frontend roots must exist (unmatched globs may be empty); overlapping roots are deduplicated.

| Configuration             | Default                                                                                     |
| ------------------------- | ------------------------------------------------------------------------------------------- |
| `frontendRoots`           | `null`: existing `resources/js` plus `uiRoots`; configure module frontend roots explicitly. |
| `frontendAliases`         | `['@/', '~/']` prefix matches; include the app's actual Vite aliases.                       |
| `descriptorResponseRoots` | `null`: `sourceRoots`; narrow this to response/ViewModel roots if needed.                   |
| `optionalRules`           | `[]`; assertions report skipped for disabled optional rules, never behavioural PASS.        |
| `uiActionComponents`      | `['Button', 'button', 'ModalLink', 'Link', 'a', 'DropdownMenuItem']`                        |
| `uiPlusIcons`             | `['Plus', 'PlusIcon']`                                                                      |
| `uiExternalIcons`         | `['ArrowUpRight', 'ArrowUpRightIcon', 'ExternalLink', 'ExternalLinkIcon']`                  |
| `uiExternalLabelComponents` | `[]`; components that render the external icon themselves. Using one inside a new-tab link counts as carrying the icon. |
| `uiDropzone`              | `app/UI/resources/js/Form/InputFile.vue`                                                    |
| `uiToastLibrary`          | `vue-sonner`                                                                                |
| `uiToastModule`           | `app/UI/resources/js/Notification/NotificationCenter.vue`                                   |
| `uiNotificationCenter`    | `app/UI/resources/js/Notification/NotificationCenter.vue`                                   |
| `uiToastRenderers`        | `[]`; extra project-relative files allowed to import the toast library because they render the toaster. Callers elsewhere still fail. |

These defaults name the cookbook kit. A host with a Dropzone or notify adapter sets its own exact project-relative owner paths. Optional checks can expose existing kit debt: enabling `ui dropzone` does not exempt other file-input components automatically.

Limits: the JavaScript lexer discards comments and treats strings/template literals as opaque. It does not resolve variables, interpolated dynamic imports or template-string expressions. `js-imports` also reads `resolve.alias` from `vite.config.ts` or `vite.config.js` and merges it with `frontendAliases`: only static object keys and `find` strings in an array literal count (a key `@app` covers `@app` and `@app/...`). Computed keys, spreads, regular expressions, aliases held in variables and other build tools are ignored; list those in `frontendAliases`. UI checks inspect literal tags, attributes, labels and icons; dynamic bindings, runtime components and indirect flash aliases need behavioural tests. PHP arrays are parsed, never executed; a `can` map outside a response can be a source candidate too. Tiptap's `can()` is a known non-authorization use; baseline the exact finding with a dated reason. No package installation, app boot or network access occurs during the source checks.
