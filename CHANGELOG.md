# Changelog

## 0.6.1 - 2026-10-13

### Added

- Shared `drafts: lint|full` across `CiTriggers`, `Configuration::ciDrafts`, app config/env and the package CLI/Composer `extra`, defaulting to `lint`. Public repos may opt out of draft guards, draft-aware concurrency and the `ready_for_review` requirement; every other check, including ready-PR Windows coverage, remains. Read visibility from the GitHub event payload offline: private repos retain lint requirements with `drafts-full-private`, unknown visibility honours full with an advisory, invalid config/env values become requirements and invalid CLI flags exit 2. Parser, entry-point, precedence and production-only smoke tests cover the contract. (#163)

## 0.6.0 - 2026-10-13

### Added

- Laravel-free `vendor/bin/standards ci` for packages, using the shared `CiTriggers` parser. Flags override process environment, then `composer.json` `extra.tey-standards.ci`, then defaults. Human findings/advisories and schema-1 JSON preserve advisory-by-default semantics: exit 1 only for requirement findings with both `--strict` and enforcement on; usage errors exit 2. (#159)
- Process-level CLI contracts, strict package CI dogfooding, and production-only Composer-bin smoke coverage without Pest, PHP-Parser or a loaded Laravel Application. Windows runs the CLI smoke before verifying deployment preflight's missing-PCNTL refusal. Package adoption uses one CI step after dependency installation and keeps settings in Composer `extra`. (#159)

## 0.5.2 - 2026-10-13

### Fixed

- Detect a missing Composer `type` as `library` (package), with configuration taking precedence and the `artisan` fallback used only without `composer.json`. (#158)
- Scope trigger, concurrency, draft-guard and matrix requirements to workflows including `push` or `pull_request`; other-event-only workflows are exempt, while mixed workflows retain their checks and every job still requires `timeout-minutes`. Document release-branch globs such as `*.x` for `ciDefaultBranches` and `preflight.ci.default_branches`. (#158)

## 0.5.1 - 2026-10-13

### Fixed

- Require `github.event.pull_request.draft` in concurrency group expressions for workflows with draft-guarded jobs and for guarded jobs' own groups (`concurrency-draft`), under the shared `PREFLIGHT_CI_ENFORCE` opt-in. Draft-snapshot runs can no longer cancel ready runs when a push is followed by `gh pr ready`. Unguarded workflows and jobs' own groups are exempt; expression whitespace and YAML quoting are supported. Rule 51, the recipe, README and workflows use draft-aware groups. (#151)

## 0.5.0 - 2026-10-13

### Added

- Pest 5 architecture support alongside Pest 4.7+, with the same lazy-result adapter and negative fixtures under both majors. Preserve targeted diagnostic wording so exact ratchet entries survive the upgrade. (#147)
- A dual-major Linux package CI matrix and a Pest 5 temp-app architecture test. Windows runs Pest 5 only to keep the matrix lean. (#147)

### Changed

- Broaden development constraints and suggestions to `pestphp/pest ^4.7 || ^5.0` and `pestphp/pest-plugin-arch ^4.0 || ^5.0`. PHP-Parser remains 5.x; production preflight dependencies and behaviour are unchanged. (#147)

## 0.4.1 - 2026-10-13

### Fixed

- Make rule 51 enforcement opt-in with boolean `preflight.ci.enforce` (`PREFLIGHT_CI_ENFORCE`, default `false`) and `Configuration::ciEnforce`. CI preflight still reports `fail` findings, but strict mode ignores them until enabled; blocking checks are unchanged. Architecture rule 51 skips with an advisory reason and finding count until enabled, then enforces the existing ratchet. Document run, fix or ratchet, then opt in so upgrades do not turn CI red. (#150)

## 0.4.0 - 2026-10-13

### Added

- `Tey\Standards\CiTriggers`, an offline workflow parser returning `workflow`, `job` and category-prefixed `reason`. Requirements cover triggers, concurrency/safe cancellation, draft guards, timeouts, app PR PHP matrices, non-Linux app legs on any event (`os-matrix`) and missing ready-PR package Windows coverage (`windows-gap`). Distribution detection uses config, Composer type, then `artisan`, otherwise advisory Unknown. Repository-wide coverage targets `.github/workflows`. (#134)
- Separate `advisories()` for uncached Composer/npm installs (`cache`), missing macOS and Unknown distribution. Preflight messages and JSON read as suggestions and never fail `--strict`; architecture checks only enforce requirements. Docs explain job consolidation, whole-minute billing, measured net-zero caching and when caching helps. (#134)
- A `[rule 51]` architecture test with exact dated ratchets. `Configuration` accepts `ciDefaultBranches` (default `['main']`) and `ciDistribution` (default `null`). Shared config is `preflight.ci.distribution` (`app` | `package`, env `PREFLIGHT_CI_DISTRIBUTION`); the unreleased package flag is removed without a compatibility alias. (#134)
- Warning-level `ci.triggers` preflight in the `ci` group. It runs with `--ci` only, fails requirements (exit 1 only under `--strict`) and keeps cache/macOS/Unknown advisories non-blocking. No workflows is advisory Unknown. (#134)
- A `skipped` result status for checks that do not apply to the current run; `ci.triggers` reports it in deploy runs. It is not a pass and is distinct from `skipped-in-ci`. (#134)

### Changed

- Require `symfony/yaml` (`^7.0 || ^8.0`) in production dependencies to parse workflow files. (#134)
- The standalone package workflow tests PHP 8.4/8.5 on Ubuntu and Windows on ready PRs and main, with concurrency, explicit PR types, draft guards and timeouts; retains Pest 5 coexistence. (#134)

### Fixed

- Pass `queue.worker` for the resolved `cloud` driver without backlog or network reads. `queue.connection` treats the platform-provided worker as expected, while `expect_worker=false` still warns about the contradiction. Sync, database, Redis and unknown-driver behaviour is unchanged. (#145)
- Load PDO SQLite on Windows and replace the shared command helper's blanket skip with test-only signal doubles. Only real alarm interruption and deployment/no-dev smoke tests skip, with dated reasons; a separate-process test retains the production command's missing-PCNTL guard. (#145)
- Assert native console line endings and close test log handles before deleting temporary directories, fixing Windows newline failures and locked-file cleanup warnings. (#145)
- Normalize source targets and native preset filesystem keys across path separators. Windows skips only PCNTL-dependent deployment-command/no-dev smoke tests, with a dated reason; parser, architecture and direct checks remain exercised. (#134)

## 0.3.0 - 2026-10-13

### Added

- Offline warning-level `brand.favicon` preflight in CI and the `brand` group, with strict failure promotion for missing/empty ICO files, current and historical Laravel default hashes, and missing or data-only icon links. Missing layout sources remain Unknown advisories. (#130)
- `preflight.brand.layouts` root-source overrides and additive `preflight.brand.known_default_hashes`, documented source commits, generated app-owned fixtures, byte-exact default fixtures and production-only smoke coverage. (#130)

### Changed

- Require ICO header `00 00 01 00` and 16×16/32×32 directory entries, a PNG touch icon of exactly 180×180 via `getimagesize` without GD, and a non-empty SVG with an `<svg` root. Asset failures name the file and problem and retain warning-level strict/plain semantics. (#130)
- Audit full skeleton and starter-kit Git histories, adding four historical skeleton ICOs and an intermediate React SVG. Publish all nineteen SHA-256 defaults and reference-only SHA-1 values in the README, with byte-exact fixtures and explicit current-per-kit coverage. Detection uses SHA-256 only. (#130)

## 0.2.0 - 2026-10-13

### Added

- Advisory `queue.worker` backlog checks for database and Redis queues, with a configurable 15-minute age threshold, unknown evidence for unsupported drivers and connection errors, CI skips and bounded reads. No heartbeat or hosting API check is included. (#124)
- Nullable `preflight.queue.expect_worker` (`PREFLIGHT_QUEUE_EXPECT_WORKER`) and `preflight.queue.max_backlog_minutes` (`PREFLIGHT_QUEUE_MAX_BACKLOG_MINUTES`), with queue policy and pre-migrate timing guidance. (#124)

### Changed

- The default queue policy is sync until needed, then the hosting platform's scale-to-zero managed queue (Laravel Cloud managed queues), never an always-on worker. `queue.connection` now passes for sync in production with the worker expectation unset or false, instead of warning. Explicit expectation conflicts warn without blocking, even under `--strict`. Messages and docs point to managed queues or deliberate sync; the checks remain driver-based and public names stay unchanged. (#124)

## 0.1.2 - 2026-10-13

### Added

- Full structured preflight results and an exit-code summary in the application log, on the `standards` channel or the default. The summary includes counts per status, active skips and CI, strict and group selection options.
- Automatic stderr summaries and failed or advisory check lines when stdout is not a TTY, with explicit `--stderr` and `--no-stderr` overrides. JSON stdout is unchanged.
- Post-deploy verification instructions for the application log and hosting command runner, plus logging and stderr coverage in the production-only smoke test.

## 0.1.1 - 2026-10-13

### Fixed

- Installing next to Pest 5 no longer fails. The package dropped its `conflict` on `pestphp/pest` and `pestphp/pest-plugin-arch`, so apps on any Pest major can install it for the deploy preflight. The architecture checks still support Pest 4 (4.7+); with another Pest major they stop with a clear message, and the preflight is unaffected. CI installs the package beside Pest 5 and runs the preflight smoke test.

## 0.1.0 - 2026-10-13

### Added

- Configurable, rule-tagged Pest architecture tests for PHP boundaries, placement, CRUDDY controllers, media models, authentication callbacks, retired namespaces and no-dev discovery roots.
- An exact, dated and reasoned debt ratchet that rejects stale, duplicate and unknown-rule entries. Laravel, security and PHP presets are supported; the strict preset covers only the package's own source.
- `standards:preflight` with blocking and warning levels, secret-aware `--ci`, failure promotion with `--strict`, logged and persisted emergency `--skip` overrides, and machine-readable `--json` output.
- An app-check contract and registry, offline core configuration checks and bounded execution.
- Production installation without Pest or PHP-Parser, PHP 8.4/8.5 CI with no-dev smoke coverage, and Laravel Boost agent guidance.
