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

Rules 12, 14, 19, 20, 21, 22, 29 and 32 cover static PHP relationships and configured placement, not complete runtime behaviour. The no-dev check covers configured development namespaces, with safe attributes permitted only as imports and attributes. Literal `getPath` calls are prohibited; dynamic method calls are not resolved. Media classification uses the configured trait, including nested traits and inherited use. Money units cannot be inferred from casts, so rule 24 is not registered. Rule 51 parses workflow YAML; see [CI triggers](#ci-triggers) for its limits.

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

Only `app.key`, `app.env-debug` and `database.config` are blocking core checks; mail, brand and CI checks have warning level and never block deployment. Blocking failures exit 1, warnings permit exit 0, and usage errors exit 2. `--strict` exits 1 on any selected failed check regardless of level, but advisory `warn` results still exit 0. CI secret skips are not passes. `--json` supplies board-readable levels and result types. Emergency `--skip=app.key,mail.transport` or `PREFLIGHT_SKIP` overrides are printed, logged and persisted until a passing check clears them. PHP CLI needs PCNTL for the five-second per-check guard. Configuration checks run offline; `queue.worker` alone reads queue storage, and `ci.triggers` reads workflow files. No hosting APIs are called. These checks do not prove credentials for other services or that a worker is running.

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

## Brand: an app-owned favicon set

Ship the app's own `public/favicon.svg` with an `<svg` root, a non-empty `public/favicon.ico` with the ICO header `00 00 01 00` and 16×16 and 32×32 directory entries, and a PNG `public/apple-touch-icon.png` of exactly 180×180 pixels. Extra ICO sizes are allowed; a zero width or height byte means 256. Link them in every root layout, including guest, app and error shells. A plain monogram on a filled tile is a fallback for an app without a mark. Check it at 16px against light and dark tab bars. Do not retain Laravel's starter icons or a `data:` suppressor.

`brand.favicon` is warning-level and reads files only, so it runs in `--ci` without secrets. A missing, unreadable or empty file, a wrong format or size, a known default in any of the three files, or a root layout with no non-data `rel="icon"` link returns `fail`: plain runs exit 0 when the blocking checks pass, and `--strict` exits 1. Each asset failure names the file and the problem. PNG type and dimensions use `getimagesize`; GD is not needed. Select it with `php artisan standards:preflight --only=brand --strict`.

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

CI runs once per change that matters. **Apps: Linux only, on every event**, with one deploy PHP version on PRs; extra PHP versions run nightly or manually. **Distributed packages: test Windows on ready PRs, before merge**, regardless of cost, and macOS where relevant (advisory). Others run them on those platforms. Distribution includes published packages, private-but-sold packages and generated output others use; an app is our own code deployed by us on Linux. Package PHP matrices may run on PRs. This is rule 51; the [CI triggers recipe](https://github.com/teylabs/cookbook/blob/main/docs/recipes/backend/ci-triggers.md) has the full workflow and required-check caveat.

```yaml
on:
  push:
    branches: [main]
  pull_request:
    types: [opened, synchronize, reopened, ready_for_review]

concurrency:
  group: ${{ github.workflow }}-${{ github.event.pull_request.number || github.ref }}
  cancel-in-progress: ${{ github.event_name == 'pull_request' }}

jobs:
  tests:
    if: github.event_name != 'pull_request' || github.event.pull_request.draft == false
    runs-on: ubuntu-latest
    timeout-minutes: 30
```

`Tey\Standards\CiTriggers` parses `.github/workflows` offline. Construct it with the base path, default branches (`['main']`) and optional `distribution: 'app'|'package'`; `findings()` returns failures as `workflow`, `job` and `reason` entries. `advisories()` returns suggestions separately in the same shape. A workflow-level entry has `job: workflow`; repository-wide coverage targets `.github/workflows`. Each reason starts with its category and says what to change:

| Category | Finding |
|---|---|
| Push branches | A `push` trigger without `branches`, or with branches other than the default branches. |
| Pull request types | A job has a draft guard, but `pull_request.types` does not include `ready_for_review`. |
| Concurrency | No concurrency on the workflow or on every job, or `cancel-in-progress` that can cancel a main run. |
| Draft guard | A test job runs on draft pull requests. |
| Timeout | A job has no `timeout-minutes`. |
| PHP versions | An app runs more than one PHP version on pull requests. Packages are exempt. |
| `os-matrix` | An app has any non-Linux runner or OS matrix entry, on any event. |
| `windows-gap` | A distributed package has no Windows leg on a ready PR, across all workflows. Main/nightly-only coverage does not count. |
| `cache` (advisory) | A job installs Composer/npm dependencies without a recognised dependency cache. Suggest measuring, never fail. |

Lint and static jobs may run on drafts. Package Windows jobs may use the draft guard: ready PRs still run them. Missing macOS and docs-only skipping are advisory.

Detection, in order: `preflight.ci.distribution` (`app` or `package`, env `PREFLIGHT_CI_DISTRIBUTION`); Composer type `project` → app, `library`, `laravel-package`, `composer-plugin` or another non-project type → package; `artisan` at the base path → app; otherwise advisory Unknown with config instructions. Generated output or sold apps use `package` explicitly if their Composer type says `project`. Repository access plays no part.

**Consolidate small jobs first.** GitHub rounds each job up to whole minutes; tiny split jobs pay that tax and repeat setup. 2m43s → 2m12s still bills three minutes: savings begin below 2:00. Cache only above ~45 s cold installs or just over a minute boundary. A measured warm Setup of 31 s → 26 s plus ~5 s restore saved about zero, against ±20 s runner noise. The advisory recognises lockfile-keyed `actions/cache`, `setup-node` with `cache: npm`, setup-php plus an `actions/cache` step on the Composer cache dir, or `ramsey/composer-install`. Composer `install`/`update` and npm `ci`/`install`/`i` without these get a suggestion, never a failure.

The `[rule 51]` architecture test reports each finding with the workflow as the target and `job | reason` as the check. Record existing debt as exact, dated ratchet entries. Pass the preflight's options to `Configuration` so both judge the same workflows. Read Laravel config inside the test callback after the application boots, not while Pest registers tests; the [preset example](https://github.com/teylabs/cookbook/blob/main/docs/reference/standards-preset.md#configure-roots) shows that setup:

```php
new Configuration(
    // ...the existing arguments
    ciDefaultBranches: config('preflight.ci.default_branches', ['main']),
    ciDistribution: config('preflight.ci.distribution'),
);
```

`ci.triggers` is a warning-level preflight check in the `ci` group. It runs with `--ci` only; deploy runs report `skipped`, not a pass. Requirements fail the check: plain runs exit 0 when blocking checks pass, and `--strict` exits 1. Cache/macOS/Unknown advisories remain `warn` and exit 0 even under strict, with suggestions in both the message and JSON. Missing workflows are also advisory Unknown. Select it with `php artisan standards:preflight --ci --only=ci --strict`.

| Configuration | Default | Meaning |
|---|---|---|
| `preflight.ci.default_branches` | `['main']` | Branches a `push` trigger may list. |
| `preflight.ci.distribution` | `null` | `app` or `package` via `PREFLIGHT_CI_DISTRIBUTION`; otherwise Composer type, then `artisan`, then Unknown. |

**Required checks.** `paths-ignore` suits repositories without required checks. With required checks, a skipped workflow never reports and blocks the merge; use a `changes` job that lets expensive jobs skip, since a job skipped by its `if` reports success. The checker does not read branch protection, so confirm the required check names by hand.

**Limits.** The checker does not run GitHub's expression engine. Ready-PR coverage resolves literal runners and matrices, include/exclude rows, `fromJSON` lists and simple event/draft boolean conditions and `condition && value || fallback` selectors. Unknown expressions do not prove Windows coverage; use a detectable leg or review and ratchet an inapplicable finding. App OS checks inspect all literal alternatives, including event-selected legs. PHP expressions choosing on `github.event_name` retain the existing exemption and need review. Reusable workflows, composite actions and wrapper scripts are not followed. The test scan recognises `php artisan test`, Pest, PHPUnit, `npm test`, `composer test`, Playwright and Vitest. Cache detection reads steps, not performance.

**This package's CI.** The standalone workflow runs PHP 8.4/8.5 on Ubuntu and Windows for ready PRs and main, and retains Pest 5 coexistence. Windows loads PDO SQLite and runs architecture, parser, backlog and command-contract tests. Test-only signal doubles exercise non-alarm command behaviour without changing production code. Only the busy custom-check timeout, busy backlog timeout and real deployment/no-dev smoke test skip without PCNTL; each has a 2026-10-13 reason. A separate process verifies that the real preflight command still exits 2 without PCNTL rather than silently losing its timeout guard. Cookbook CI normally runs Linux only; issue #145 proves the package on GitHub's Windows runner with a temporary cookbook job removed before review.

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

## Agent guidance

The package ships a Laravel Boost guideline. Select `tey/standards` during `php artisan boost:install` to include it in the application's agent instructions.

*Changelog: 2026-10-12 — trial `tey/standards` package with configured checks, exact stale-checked ratchets, seeded negative controls and a package-source-only strict preset.*
*Changelog: 2026-10-13 — added offline deploy preflight, registered checks, CI mode, timeout guards and persistent emergency skips; mail warns at deploy time, while generic --strict promotes failed results but not advisories for CI. (#105)*
*Changelog: 2026-10-13 — prepared v0.1.0 for public release, with production-only preflight dependencies, optional development architecture tools, no-dev boot coverage and Boost guidance. (#105)*
*Changelog: 2026-10-13 — log every preflight result and summary, add automatic and explicit stderr reporting without changing JSON stdout, and document verification after deploy. (#123)*
*Changelog: 2026-10-13 — prepared 0.2.0 with sync until needed, then scale-to-zero managed queues; added driver-based advisory backlog evidence, nullable worker policy and deploy-timing guidance. Command-output regressions cover managed-queue remedies for policy conflicts, old backlog and unknown evidence. (#124)*
*Changelog: 2026-10-13 — prepared 0.3.0 with offline warning-level brand.favicon, documented current and historical defaults, layout overrides, extra hashes, CI coverage and strict failure promotion. (#130)*
*Changelog: 2026-10-13 — audited full upstream histories, published all nineteen defaults with reference-only SHA-1 values, and added warning-level ICO header/directory, PNG type/180×180 and SVG-root checks without GD. (#130)*
*Changelog: 2026-10-13 — prepared 0.4.0 with the shared rule-51 parser and architecture/preflight checks, distribution detection, Linux-only apps, ready-PR Windows package coverage, advisory cache/macOS/Unknown suggestions and measured job-size guidance; Windows package CI and dated PCNTL limits. (#134)*
*Changelog: 2026-10-13 — Cloud managed queues pass without backlog or network inspection; Windows command contracts use test-only signal doubles, leaving only real PCNTL-dependent tests skipped and retaining the production guard. (#145)*
