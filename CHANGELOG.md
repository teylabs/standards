# Changelog

## 0.11.35 - 2026-10-29

### Fixed

- **`boost` no longer fails a fresh checkout or CI because generated skills are missing.** Laravel Boost writes skills into gitignored agent directories per checkout, so the rule failed wherever `boost:install` or `boost:update` had not yet run. A required skill absent from every `boostSkillRoots` directory now passes when `boost.json` lists it under `skills`, or when a Composer `post-install-cmd`, `post-update-cmd` or `post-autoload-dump` script runs `boost:update` or `boost:install`. Skill files that are present are still checked, and an absent skill nothing regenerates is still reported, now with a hint to commit it or generate it per checkout.

### Upgrading

- Apps that allowlisted `boost` findings for missing skills can drop those entries once `boost.json` lists the skills or a Composer hook runs `boost:update`; the entries then go stale. The finding text is unchanged, so entries for skills that are still missing keep matching.

## 0.11.34 - 2026-10-29

### Added

- **Catalog leaf `mail-notifications.intake` for inbound email**, ready with the Bakery's `mod:butter/email-intake {Name}` recipe. The mail-notifications branch reaches it through a new answer, "Receive email into the app". Its rules cover a verified provider signature (Svix-style HMAC-SHA256 with a 300-second tolerance), idempotent message ids, queued extraction into a draft a person reviews, no PII in logs, and the webhook's rule 55 declaration.

### Changed

- **`data-actions.document` is ready.** It is built with the Bakery's pdf-reports recipe. Its summary and rules now name the generated policy's 403 test, and sending or attaching the PDF is still marked as not covered.
- **`mcp.agent-docs` is ready, with the Bakery's `mod:butter/agent-docs {Name}` recipe.** It serves a public `/llms.txt` index built from the `agent-docs.features` registry, plus the registry validator and the CI drift check. The leaf no longer claims rule 55, because the capability manifest and parity guard belong to `mcp.write`.
- **A ready catalog leaf needs a `make` or a `recipe_make` command**, so a leaf that only the Bakery generates can be ready. Its `preferred_make` is null when the recipe package is not installed.

## 0.11.33 - 2026-10-29

### Added

- **New opt-in rule `destructive-db` checks that a service provider prohibits destructive database commands outside testing.** A `migrate:fresh --env=testing` with no `.env.testing` silently falls back to `.env` and wipes the local database. Listed in `Configuration::optionalRules`, the rule requires a service provider's `boot()` to call `DB::prohibitDestructiveCommands(...)` with a guard that is true outside testing, such as `! $this->app->runningUnitTests() && ! config('database.allow_destructive')`. A missing call, a bare call or `true` (which blocks `RefreshDatabase` too), a production-only guard and `env()` in the guard are reported. A deliberate local reset uses a one-off `ALLOW_DESTRUCTIVE_DB=1 php artisan migrate:fresh`, read through a config key; the README explains why the flag never goes in `.env`. Off by default, so existing apps see no change.

## 0.11.32 - 2026-10-29

### Fixed

- **Rule 55 no longer passes mutating routes the capability manifest never declares.** It checked only the actions listed in `docs/mcp-capabilities.json`, so a POST, PUT, PATCH or DELETE route with neither an MCP capability nor a UI-only declaration passed silently. It now parses the route files under `routeRoots` (module routes included) and reports every undeclared mutating route against its file and line, with its method, URI, name and controller action. A capability covers routes through a new optional `routes` list (route names or `"METHOD /uri"`), and a capability marked `"uiOnly": "<reason>"` covers routes that stay out of MCP; a `uiOnly` without a reason is reported. Unparseable route files, non-literal URIs and an empty `routeRoots` are reported rather than passed. Auth, framework and broadcasting routes are excluded by the new trailing `Configuration::mcpRouteExclusions` (default `McpParity::ROUTE_EXCLUSIONS`), as are webhook routes with signature middleware; vendor routes are never scanned. An app with no manifest still gets only the missing-manifest finding.

### Changed

- **`standards:make` now writes capability entries to `docs/mcp-capabilities.json`** for every mutating route it generates (crud, workbench, settings-section, webhook, including modular hosts): in the `routes` of an existing `<table>.write` capability for `crud --mcp`, otherwise a `uiOnly` placeholder `generated.<unit>.<name>` with the reason `generated: confirm or map to an MCP tool`. The merge is idempotent, and a host without a manifest is left untouched. A downstream scaffold whose drift test mirrors `standards:make` output will see the manifest change.
- Rule 55 also reports a capability `routes` entry that no route file defines.

### Upgrading

- Apps with a capability manifest will see new rule 55 findings, one per undeclared mutating route. Declare each route in the `routes` of the capability whose MCP tool covers it, or in a capability marked `"uiOnly"` with the reason it stays out of MCP. Extend `mcpRouteExclusions` for app-specific framework routes, or allowlist a finding with a reason and expiry.
- When `standards:make` adds capability entries, it rewrites `docs/mcp-capabilities.json` as pretty-printed JSON with 4-space indents, so an existing manifest in another style may be reformatted. Review the diff, and commit the reformat on its own if you want a clean history.

## 0.11.31 - 2026-10-29

### Changed

- **Kit export snapshot refreshed to tey/kit v0.5.22** (`standards kit-exports --refresh`), adding `ActivityStream`. The snapshot test's fixture is now v0.5.22's barrel (`fixtures/KitExports/index-v0.5.22.ts.stub`) and expects it.

## 0.11.30 - 2026-10-29

### Fixed

- **`standards:allowlist --stamp-expiry` stamps one-line entries** (`['rule' => …, 'date' => '…']` on one line), straight after the date pair. An entry with no literal date to stamp after is now skipped and listed (`skipped` in `--json`) instead of aborting the whole run.
- **`standards:allowlist` without `tests/Arch/configuration.php`** no longer emits a PHP warning while it looks for the allowlist path.

- **An allowlist entry for an advisory goes stale.** Once an `operator` or `integrations` advisory stops firing, its entry is reported stale (`standards:check`) and fails the Pest ratchet, as a findings entry does. While it fires, an allowlisted advisory is acknowledged and no longer repeated.
- **`operator` false positives:** a command the scheduler runs (`Schedule::command(...)`/`$schedule->command(...)`, by class or by name, in the source roots, `routes` or `bootstrap`) is not reported, and ConfirmableTrait's `confirmToProceed()` counts as a production guard.

### Changed

- **`js-imports` hints the parent's range for a transitive package.** An undeclared import of a package that a declared one depends on (`@inertiajs/core` under `@inertiajs/vue3`) gets a hint naming that range, the npm `overrides` alternative and `npm ls <package>`. Two copies of `@inertiajs/core` break vue-tsc.

### Documentation

- **Pre-push and a global `core.hooksPath`:** `standards:install` writes no pre-push hook when `core.hooksPath` is set outside the repository, globally included. It prints the line to add instead; the README says why and how to find the setting.

## 0.11.29 - 2026-10-29

### Fixed

- **`integrations` follows one webhook delegation.** A webhook route whose invokable controller calls an injected handler (`$handler->handleWebhook(...)` on a typed parameter or property) no longer reports "does not go through a signature validator" when that handler's called method verifies (it wraps `(new VerifyWebhookSignature)->handle(`, say) or the handler extends a verifying controller. A subclass of Cashier's `WebhookController` counts as verified, since it inherits the middleware. A delegated handler that doesn't verify is still reported.

### Documentation

- **Helper or feature roots and `preset laravel`** (README, "A modular app's configuration"). An `app/Helpers/*` layout's models, requests and controllers are left out of the stock Laravel preset by adding `App\Helpers` to `presetIgnores` (since 0.11.11). The README gives the trade-off (the whole preset, with placement left to rule 20) and the stale-allowlist cleanup.

## 0.11.28 - 2026-10-29

### Upgrading

- **`standards:preflight --strict` on a Cloud managed queue now fails `queue.failed-store`** until the post-deploy check covers both failed-job stores and `PREFLIGHT_QUEUE_FAILED_STORE_CHECKED=true` is set.

### Added

- **Preflight `queue.failed-store`** (advisory). On a Laravel Cloud managed queue (driver or connection `cloud`, or a committed Cloud config naming a managed queue), failed jobs never reach `failed_jobs`. The check names both stores and their commands (`php artisan queue:failed` and `cloud managed-queue:failed-jobs <env> --json -n`). It exits 1 only under `--strict`, until `PREFLIGHT_QUEUE_FAILED_STORE_CHECKED=true` declares a post-deploy check that reads both. Other queues pass. "Verify after deploy" now says "0 failed jobs" means every store.

### Changed

- **A kit newer than the snapshot is an advisory, not a warning.** When the installed kit's exports are a superset of the snapshot, `standards:check` and `standards:pattern` print `Advisory: the pattern catalog's kit snapshot is older than the installed tey/kit (new: …)`. A kit missing snapshot components still warns.
- **A weekly workflow opens the snapshot refresh.** `.github/workflows/kit-snapshot.yml` compares the latest tey/kit release's barrel with the snapshot and opens (or updates) one issue with the refresh commands.

### Fixed

- **`standards:install --dry-run` dry-runs the whole install.** Without `--extract` the flag was ignored and the install wrote skills, hooks and `tests/Arch/configuration.php`. Now every write goes through one place: a dry run lists each file it would write (`created` or `modified`) and writes nothing, and `--json` prints `{dry_run, installed, files}`.
- **`preset security` leaves seeders and factories out.** `rand()`/`mt_rand()` there make fake data. New `Configuration::$securityExemptRoots` (default `database/seeders`, `database/factories`); app code stays checked.
- **The kit export snapshot is tey/kit v0.5.13's barrel**, which adds `Badge`, `EmojiTray` and `RadioCardGroup`.

## 0.11.27 - 2026-10-28

### Fixed

- **`versioned-writes` skips migrations:** any file under a `migrations` directory (`database/migrations`, a module's `Database/Migrations`, kit migration stubs). A data backfill in a migration, which may predate the version column and runs once outside the stale-guard flow, is no longer reported. The same write in app code still is.

## 0.11.26 - 2026-10-28

### Upgrading

- **New rule `versioned-writes`,** on in apps with a versioned model (a trait named `Versioned`, `HasVersion` or `OptimisticLock`, or `Configuration::$versionedModels`). A query-builder write that skips the version bump now fails the gate. Bump it in the write, write through the model instance, or allowlist it with a reason and an expiry.

### Added

- **Rule `versioned-writes`** (README): a builder `update`/`upsert`/`increment`/`decrement` on a versioned model's query, or on `DB::table('<its table>')`, must set the version column (`DB::raw('version + 1')` or a value). Instance writes, which run the model events, aren't reported.
  - Findings give the file, the line and a fix.
  - New `Configuration::$versionedModels` and `$versionColumn` (default `version`).

## 0.11.25 - 2026-10-28

### Fixed

- **The `descriptors` rule no longer flags Tiptap's `editor.can().undo()`.** `can(`/`cannot(` now counts only as a bare call (`can('x')`, `$can(`) or on an auth-shaped receiver (`user`, `auth`, `$page.props.auth…`, `page.props.auth…`, `permissions`, `gate`, `policy`). A method on any other object (`editor.can()`, `chain.can()`) isn't flagged.

## 0.11.24 - 2026-10-28

### Fixed

- **The `descriptors` rule no longer flags ARIA or domain roles.**
  - The bare comparison `role === …` matched an ARIA role (`el.role === 'textbox'`, `role: 'textbox'` in an editor config) and a domain role (`FeedRole`, `node[role]`, `item.role === 'object'`), giving five false positives in one client app.
  - A role or permission comparison is now flagged only against a known role name (`owner`, `admin`, `member`, `editor`, `viewer` and the like), in either order.
  - The other auth-shaped checks are unchanged, and `cannot(` and `hasAnyRole(`-style helpers are now caught too.

## 0.11.23 - 2026-10-28

The same change was first tagged v0.11.21, out of order after v0.11.22. Composer resolves 0.11.22 over it, so take 0.11.23.

### Changed

- **The kit export snapshot is tey/kit v0.5.4's barrel**, which adds `DescriptionList` and `EmptyState`. The pattern catalog uses them:
  - `ui-page.show` gains a "fields" slot: DescriptionList, the record's fields as a real `dl`, with copy for an id or key;
  - `ui-page.external-sync` gains a "not linked" EmptyState, offering Link;
  - `ui-page.live-console` gains a "no session" EmptyState (compact inside a panel), and both leaves' empty-state rules name it.

## 0.11.22 - 2026-10-28

### Changed

- **The PDF report leaf (`data-actions.document`) has a recipe:** the Bakery's `mod:butter/pdf-reports {Name}`. It's now `draft`, not `gap`.
  - Its rules: render through the Renderer contract (mPDF stays behind it); authorize through a generated policy; stream with `nosniff` and `Content-Disposition`; no PII in logs; the app requires `mpdf/mpdf`.
  - Sending or attaching the PDF is marked as not covered yet.
- **Contributors:** a downstream scaffold mirrors `standards:make capability-tool --flavor=write` and has a drift test. Any change to that generator's output shape is announced here under Changed (noted in `Maker` and the README).

## 0.11.20 - 2026-10-28

### Changed

- **`ui-page.external-sync`:**
  - Credentials stay server-side. A client-side picker (a provider's browser file picker, say) may receive a short-lived, narrowly scoped access token and a restricted, referrer-locked API key, but never a refresh token.
  - Replace uses the kit's typed confirmation (ConfirmDialog `confirmText` / descriptor `confirm.typed`) once it ships.

## 0.11.19 - 2026-10-28

### Fixed

- **Real phrasings find the new build-your-own leaves.** "invite a guest to one resource" planned the manage-records CRUD feature, and "guest presenter" or "guest reviewer" landed on the signed-link guest page; all now rank `access-tenancy.membership.scoped-guest` first.
  - The scoped guest, paired remote, device pairing, stage display, live audience, external sync and live console leaves gain natural synonyms ("phone remote", "projector screen", "audience voting on phones", "import from Google" and the like). Each phrasing has a resolver test.
  - **Specificity rule:** when a leaf matches a longer whole keyword phrase than any feature does, the leaf wins over the feature's plan. A feature's own id or phrase still plans it.

## 0.11.18 - 2026-10-28

### Added

- **Seven build-your-own leaves** in the pattern tree (README: Build-your-own recipes). They are `draft` leaves with rules but no generator, each from patterns one app has, each saying when it graduates:
  - `ui-page.external-sync`;
  - `ui-page.live-console`;
  - `ui-page.stage-display`;
  - `ui-page.audience-live`;
  - `ui-page.remote` with `access-tenancy.device-pairing`;
  - `access-tenancy.membership.scoped-guest`.
  - For example, `standards:pattern "sync with an external source"` resolves to `ui-page.external-sync`, which links `background.job` and `ui-page.external-link`.
- **Leaves can carry `rules`, `related` and `graduate`.** The compiler validates them (`related` must name other leaves), the lookup returns them, and the generated skill lists them.

### Changed

- **Tree paths moved for a few answers:**
  - Workbench and activity stream now sit under a new "A workflow page beyond CRUD" question.
  - The signed-link guest page is reached through "Does a second device act for a signed-in user?".
  - Exhibit and kiosk sit under "A live or in-person experience".
  - Workspace membership is now `access-tenancy.membership.workspace`, under a new "Who is being let in?" question. Its evidence moved with it.
  - Leaf ids are unchanged except for that last one. Stamps aren't checked against leaf ids, so files stamped `access-tenancy.membership` keep passing.

## 0.11.16 - 2026-10-28

### Fixed

- **Rule `tenant-aware` no longer fails open.** A single-database app scoped by a `workspace_id` column (no `UsesTenantConnection`) matched no model with the default `tenantModels`, so the rule reported zero findings while seven commands and jobs needed fixing.
  - **Default detection now also finds** models with a tenant-style trait, a tenant global scope, or a tenant key in `$fillable` or in their table's migration. `Configuration::$tenantKeys` defaults to `workspace_id` and `tenant_id`.
  - **`tenantModels`**, when set, stays the whole answer.
  - **The rule is now on** for apps requiring tey/kit or tey/concierge, as well as spatie/laravel-multitenancy.
  - **An active rule that finds no tenant-scoped model** reports the advisory "tenant-aware checked nothing", and the Pest test is skipped with the same reason, never a silent pass.

- **`--changed` finds the trunk.** It defaulted to `origin/main`, so in an app whose trunk is another branch (`live`) it reported every file and blocked pushes. The default is now the remote's default branch (`refs/remotes/origin/HEAD`), else `origin/main`, else `main`. `STANDARDS_BASE_REF` and `--base` still override, and the ref used is printed on stderr.

### Added

- **`standards:check --strict`:** advisories fail too (exit 1).
- A check's finding can mark itself advisory. The gate lists it under `advisories`, outside the ratchet.
- The README has a section for single-database, column-scoped apps.

## 0.11.17 - 2026-10-28

### Added

- **Preflight `deploy.migrate`:** every deploy must run `php artisan migrate --force`, since packages rely on `MigrationsEnded` and `NoPendingMigrations` firing on each deploy.
  - It reads what the repo commits: `preflight.deploy.script` (`PREFLIGHT_DEPLOY_SCRIPT`), Laravel Cloud's `cloud.yml` or `.cloud/`, Forge or Envoyer scripts, deploy workflows, and composer `deploy` scripts.
  - It passes when the step is there, and fails when a found script lacks it. When nothing is found (a Cloud dashboard deploy), it warns with a hint to commit the deploy commands and declare the path.
  - It's advisory-level, and packages are skipped.

## 0.11.15 - 2026-10-28

### Upgrading

- **New rule `tenant-aware`, on in apps that require spatie/laravel-multitenancy.** A command or queued job that touches tenant-scoped models without Spatie's `TenantAware` (or a tenant-aware queue config) now fails the gate. So do a `NotTenantAware` job that touches them, a tenant-scoped prunable model whose `prunable()` keeps the scope, and `execute(fn () => dispatch(…))`. Fix each one the package's way (README), or declare a deliberate cross-tenant admin command in the allowlist with a reason and an expiry.

### Added

- **Rule `tenant-aware`** (README): spatie/laravel-multitenancy's `TenantAware` command trait and `TenantAware`/`NotTenantAware` job interfaces, read with the app's `queues_are_tenant_aware_by_default`, `tenant_aware_jobs` and `not_tenant_aware_jobs`.
  - It also checks landlord prunes, and the dispatch-in-an-arrow-fn gotcha inside `execute()`.
  - Findings give the file and line, and a fix.
  - `Configuration::$tenantModels` names what makes a model tenant-scoped (default: Spatie's `UsesTenantConnection`).
- **`standards:make command` and `job` emit Spatie's `TenantAware`** in a multitenancy app. `--landlord` opts out: the class says it's landlord-only, and a job implements `NotTenantAware`.
- A check's finding may carry a `line` and its own `hint`. `standards:check` prints `path:line`, and `--json` adds `line`. Allowlist keys are unchanged.

### Fixed

- `standards:make webhook` decided whether the app had spatie/laravel-multitenancy from this package's own environment. It now reads the app's composer.json or vendor.

## 0.11.14 - 2026-10-28

### Upgrading

- **Every allowlist entry needs an `expires` date in the Pest suite too.** The Arch suite now fails an entry without one, as `standards:check` already did (`missing-expiry`), so the two agree. To date legacy entries in one go, run `php artisan standards:allowlist --stamp-expiry=YYYY-MM-DD --dry-run`, review it, then run it without `--dry-run`. It adds `'expires' => '…'` after each undated entry's `date` line and never changes an existing expiry.

### Added

- **`standards:allowlist --stamp-expiry=YYYY-MM-DD`**, with `--file`, `--dry-run` and `--json`.
  - It finds the allowlist that `tests/Arch/configuration.php` requires, and stamps only undated entries.
  - It refuses a date that isn't real or is already past.
  - It reads the file back and refuses unless it holds the same entries, each now dated.

### Fixed

- **`[preset laravel] could not run: Typed property …ObjectDescriptionBase::$path must not be accessed before initialization`** in `standards:check`.
  - A dev package that maps the app's namespace to its own folder (laravel/pint and laravel/cloud-cli map `App\` to theirs) put its classes into the Laravel preset's targets as vendor objects, which Pest describes without a path.
  - When one failed an expectation (`App\Concerns` holding a class, `App\Enums` holding non-enums), there was no path to report and the whole preset errored.
  - Vendor objects inside the app's own class-root namespaces are now left out of the presets; other vendor targets are unchanged.
  - A parity fixture covers it: the gate and Pest agree, with no error.
- **The pattern catalog's tey/kit export snapshot** is refreshed to v0.4.33's `@kit/ui` barrel: `Collapsible`, `PasskeyOffer`, `PasswordRules`, `StageNotice` and `Surface` were missing, so `standards:check` warned on current kits.

## 0.11.13 - 2026-10-28

### Added

- **`standards:install --extract`:** the migration path for an app whose Arch `ConformanceTest` builds its `Configuration` inline (the older pattern), which `standards:check` can't read.
  - It moves the one `new Configuration(...)` into `tests/Arch/configuration.php`, with the file's `use` imports and the top-level variables the expression reads, and rewrites the test to `require` it. The Pest suite and the CLI gate then share one Configuration and report the same findings.
  - It never overwrites. It refuses with the reason, writing nothing, when there are two inline Configurations, when the expression uses `$this` or a variable it can't carry, or when `__DIR__` would change meaning.
  - `--dry-run` and `--json`, as `standards:make` has.

### Changed

- **When the configuration is missing, `standards:check` names the fix:** `standards:install --extract` when an Arch test builds the Configuration inline (naming that test), otherwise `standards:install` to scaffold one. It still fails closed.

## 0.11.12 - 2026-10-28

### Fixed

- **`standards:make` generators fit any host**, not only a laravel app with the whole kit. Reported from a modular host: a tey/mod layout extending `modules`, kit core only. There, `standards:make workbench SlideWorkbench` wrote into the base `app/` folders, named every file `SlideWorkbenchWorkbench*`, imported the unaliased `@kit/ui`, and used kit workspaces the app had not adopted. Across every unit (crud, model, workbench, page, action, capability-tool, settings-section, command, webhook, api-client):
  - **Placement goes through tey/mod** in a layout that places by a dimension, with the new `--module=<Name>` (required there). Class names, namespaces, page render names (`<Module>::<name>`) and route-file paths follow in every generated file. A laravel host gets exactly the files it got before.
  - **A unit's suffix is never doubled.** `SlideWorkbench`, `BillingSettings` and `ReadPostsTool` are base names plus their suffix.
  - **Pages import the kit from `@kit/ui` only where the host aliases it,** else from `@/components/Kit`.
  - **A unit is refused, naming `kit:add …`, when the host has not adopted the tey/kit features its code uses** (workspaces and access for crud and workbench, workspaces for a tenant model, mcp for a capability tool). A workbench is also refused while the host's copy of the kit components predates the Workbench. Nothing is ever emitted against absent classes.
- The page index, page form and settings stubs follow tey/kit 0.4.24's props: `Field` and `Checkbox` take an `id`, `Placeholder` a `description`, and the index lists its rows without the `DataTable` (which takes a TableQuery table).

### Added

- **`standards:make --dry-run` and `--json`:** the files a unit would write (path, kind, created or modified) with nothing written, as JSON with `--json`, matching tey/mod's generators.
- **`Configuration::$presetIgnores`:** namespaces Pest's stock Laravel preset doesn't apply to, such as a modular app's module roots.
- The README documents a modular app's configuration.

## 0.11.11 - 2026-10-28

### Fixed

- **A configured root that doesn't exist no longer crashes a rule.** A controller, ViewModel, page, source or frontend root naming a folder that isn't there yet (a fresh module with no controllers, an app before its first controller) made `Source` throw, so `standards:check` reported the toast, rule 21 and other rules as errors. Every rule now skips the missing root.
  - A full run reports each missing root once as a `configuration` advisory, never as drift; a `--changed` run doesn't mention it.
  - Globs that match nothing aren't counted.
  - Tested with a fixture whose configuration names a missing module controller, ViewModel and page root, and no `app/Http/Controllers`.

## 0.11.10 - 2026-10-28

### Added

- **`php-tag` rule** (`standards:check`, `Conformance::TESTS`): every `*.php` file under the test roots and the source roots must open with `<?php`. Without the tag, PHP treats a test file as plain text: Pest prints it and runs nothing, so a whole spec goes dark without a failure. The rule allows only what PHP allows before the tag, a UTF-8 byte-order mark and then a `#!` line, and names each offending file as an error.
  - Templates aren't checked: `*.blade.php` anywhere, and any `*.php` under a `resources/views` directory. A tey/mod module keeps its views under a source root (`app/Modules/<Module>/resources/views`).
  - The new `testRoots` setting (default `['tests']`) says where test files live. A root that doesn't exist yet is skipped.
  - Existing debt can be ratcheted like any rule.

## 0.11.9 - 2026-10-28

### Upgrading

- Requires tey/kit ^0.4.20 for development: `standards:make workbench` emits code on its `WorkbenchViewModel` and `<Workbench>`. The generator refuses, with a message, when the app's tey/kit is older.

### Added

- **`standards:make workbench <Name>`** scaffolds a tey/kit Workbench page in a workspace, a multi-pane editor for a sequence of items with a live preview. It writes:
  - a provider for the kit wiring (capability, a view and an update permission, routes, navigation, and `stale_write` as 409), and a policy (capability, then entitlement, then permission);
  - the page controller and the order controller, with its FormRequest;
  - a principal-first `Reorder<Name>Workbench` Action that takes an order only at the version it was made against;
  - a stand-in item Query to replace with your own model;
  - a `WorkbenchViewModel`;
  - `Page.vue` with the navigator, stage and inspector regions and one registered inspector;
  - a self-contained feature test: the page and its props, a reorder at the current version, a stale reorder refused with 409 and nothing changed, a viewer who can't reorder, a non-member, and the capability switched off.
- **The `ui-page.workbench` leaf is ready** (it was a gap; evidence: 5 apps × 25 changes, 10 past apps × 40), built on `@kit/ui` Workbench, WorkbenchNavigator, WorkbenchInspector and WorkbenchZoom. The tree's answer now reads "A workbench: edit a sequence of items with a live preview".
- **Feature plan `sequence-editor`**, "Edit a sequence of items with a live preview": the workbench scaffold, the reorder Action and the save toast; with each item a record of its own, the domain model; and with assistant access, `mcp.read` (tey/kit's `WorkbenchReadTool`).

### Changed

- `kit-exports.json` is refreshed from tey/kit's barrel, which now exports the Workbench components. The snapshot counts only PascalCase exports as components, so an all-caps constant the barrel exports (the Workbench's reserved `DOCUMENT` and `FALLBACK` types) isn't taken for one.

## 0.11.8 - 2026-10-27

### Fixed

- **Preflight accepts the Passport keys Passport loads.** The install check refused a `PASSPORT_PRIVATE_KEY` or `PASSPORT_PUBLIC_KEY` written on one line with literal `\n` escapes, though Passport turns those into newlines (`PassportServiceProvider::makeCryptKey`). The check now normalises the config value exactly as Passport does, then asks OpenSSL to read it as league/oauth2-server's `CryptKey` would. It checks both keys. Key files are read as they are.
  - Accepted: real newlines (including Windows `\r\n`) and literal `\n` escapes.
  - Refused: base64 (Passport doesn't decode it; the message says so), garbage, and a PEM OpenSSL can't read.
  - The failure message names the accepted forms.

## 0.11.7 - 2026-10-27

### Upgrading

- Requires tey/kit ^0.4.15 for development: workspace-owned models use its `BelongsToWorkspace` and `GuardsStaleWrites` concerns. Code from `standards:make model --tenant` and `standards:make crud` needs tey/kit 0.4.15 or later in the app. Both refuse, with a message, when it is missing.
- `standards:make model <Name>` now defaults to `--flavor=domain` (below). `--flavor=plain` still runs tey/mod's `make:model` and stamps the result.
- crud resources generated from now on get their model from the domain-model generator, so their tests create and read rows inside a workspace. Existing generated code is unchanged.

### Added

- `standards:make model <Name> [--tenant] [--soft-deletes] [--enum=<field>:<Enum>]... [--media] [--sortable]` emits:
  - the model;
  - its migration: indexes, enum columns the database constrains (a check constraint on SQLite and PostgreSQL), and `lock_version`;
  - a factory, and a backed enum per `--enum`;
  - tests on the app's own database, never skipped: factory validity, and the database's refusal of a value outside each enum.
- With `--tenant`, the model is workspace-owned through tey/kit's `BelongsToWorkspace` and `GuardsStaleWrites`. It gets:
  - a `workspace_id` constrained with `restrictOnDelete`;
  - a unique `(workspace_id, id)` as the target for child tables' composite foreign keys;
  - workspace-first indexes, and a capability-gated policy;
  - tests for cross-workspace isolation (read, save, delete, and `workspace_required` outside a workspace), the workspace foreign key, and the stale-write refusal.
- The other flags:
  - `--media` adds spatie/laravel-medialibrary attachments, and is refused without that package;
  - `--sortable` adds a `position` column, an ordered scope, and puts new rows last.
- An app whose `composer.json` does not map `Database\Factories\` gets the mapping added, and the command says to run `composer dump-autoload`.
- `data-actions.domain-model` is a ready leaf that merges the workspace-model and enum/constraint clusters, with their combined evidence. It is part of the `manage-records` plan.

### Changed

- `standards:make crud` builds its model, migration, factory and policy with the domain-model generator (`--tenant`, plus `--soft-deletes` when asked). Its update action saves through `updateIfCurrent()`, its tests read stored rows past the workspace scope explicitly, and its index trail is rooted at the kit's home crumb.
- Generated files end with exactly one newline, sortable models use `self::` and generated imports are sorted, so the output passes Pint's Laravel preset.

### Removed

- The `data-actions.tenant-model` and `data-actions.constraints` gap leaves. `data-actions.domain-model` replaces both.

## 0.11.6 - 2026-10-27

### Added

- **Inbound webhooks (pattern `integrations.webhook`, ready).** `standards:make webhook <Provider>` writes a spatie/laravel-webhook-client receiver (run `composer require spatie/laravel-webhook-client` first):
  - its `config/webhook-client.php` entry;
  - a signature validator: HMAC-SHA256 over `<timestamp>.<raw body>` compared with `hash_equals`, and a ±300 s timestamp tolerance. A bad signature or an expired timestamp is a 403, nothing is stored, and the body and secret are never logged;
  - a profile that stores any signed event with an id and type;
  - a queued job whose `HANDLERS` map is the event allow-list. An unknown event is stored, not processed. The handler runs in the transaction that marks the call processed, so a failed handler retries with no double effect;
  - an example handler, and a test proving all of the above against the app's test database.
  - The first webhook adds the shared pieces:
    - `App\Models\WebhookCall`, keyed by event id (unique per webhook name), so a duplicate delivery is answered 2xx and processed once;
    - the `ReceiveWebhook` controller (404 for an unknown name, 403 for a bad signature);
    - the migration;
    - `routes/webhooks.php`, loaded by `WebhookServiceProvider` outside the web group (no session, cookies or CSRF) under its own `webhooks` rate limit.
  - With spatie/laravel-multitenancy installed, the job is `NotTenantAware`: a webhook arrives with no tenant.
- **API clients, SDK-first (patterns `integrations.api-client` and `integrations.api-client.http`, ready).** `standards:make api-client <Provider> [--sdk|--http]` writes:
  - the contract the app depends on, and an exception (in `App\Exceptions`) that carries no request data;
  - the real client: the official SDK behind the contract (`--sdk`), or Laravel's Http through a macro with base URL, token, timeout and up to three tries with backoff on a connection error or a 5xx (`--http`, the default);
  - a Fake bound in tests, its provider, `config/services.php` keys and `.env.example` lines;
  - tests on the Fake (`Http::preventStrayRequests()`) and, for `--http`, on faked HTTP. No test reaches the service.
- **Tree:** `integrations.provider` now asks what you need (receive webhooks, or call the API). A new branch asks "Is there an official SDK, and a fake driver for tests?" and leads to the SDK or the HTTP client. Evidence: fleet 4 apps × 15, past 12 apps × 28.
- **Rule `integrations`, advisory** (like `operator`, until listed in `Configuration::optionalRules`):
  - a route whose URI mentions `webhook` must reach a handler that verifies its sender;
  - an `Http::` call belongs in a class that implements a contract (a service provider, which defines macros, is exempt).

### Changed

- Generated PHP files have their import block sorted after rendering, so names taken from the unit's name still pass Pint's `ordered_imports`.
- A provider added to a `bootstrap/providers.php` written with imports is imported and listed by its short name; a plain list keeps the full name.

## 0.11.5 - 2026-10-27

### Fixed

- **`standards:install` no longer infers a ViewModel base the app doesn't have.** In an app with neither tey/kit, nor a `ViewModel.php` in `app/ViewModels`, `app/UI` or `app/Support`, nor spatie/laravel-data, it wrote `typedViewModel: 'App\ViewModels\ViewModel'`, so rule 20 stopped with "could not run: Class … does not exist" and `standards:check` reported drift on any fresh app.
  - It now writes `typedViewModel: ''` and `viewModelBases: []`, and its note says no base was found.
  - Rule 20 treats an empty typed base as none: it finds ViewModels by suffix, checks their roots and suffix, and requires no base.
  - `standards:make crud` still uses tey/kit's ViewModel when the configured base is empty.

## 0.11.4 - 2026-10-27

### Upgrading

- `standards:make command` no longer delegates to tey/mod's `make:command`. It writes an operator command and its test from the package's stubs (flavors `backfill`, the default, `maintenance` and `report`). Use `php artisan make:command` for a plain command.
- `standards:check --json` gains an `advisories` key (schema 1, additive). The text summary adds ", N advisories" when there are any.

### Added

- **Operator commands (pattern `background.command`, now ready):**
  - `Tey\Standards\Operator\Operator::run()` runs a command's work as a dry run unless `--apply`. It's idempotent by contract, so a re-run reports already done, and it's refused in production unless allow-listed (`standards.operator.allow` or `STANDARDS_OPERATOR_ALLOW`) or run with `--force` and the name typed back.
  - Commands are locked to one run at a time, and fail closed without a lock store.
  - A summary table (processed, changed or would change, skipped, failed, by reason) exits 1 on failure and 2 when refused, and never prints record data or exception messages.
  - `Operator::report()` is the read-only form.
  - `standards:make command <Name> [--backfill|--maintenance|--report]` stamps the command and a test that proves the dry run writes nothing, `--apply` is idempotent, and production refuses.
  - Evidence: fleet 6 apps × 11, past 18 apps × 129.
- **Rule `operator`, advisory:** a command that writes must run through `Operator::run`, or have a dry-run option and a production check. The new `Gate::ADVISORY` reports its findings as advisories that never fail `standards:check`, until an app lists `operator` in `Configuration::optionalRules`.

### Changed

- A generated test outside the folders `tests/Pest.php` covers now imports the app's TestCase into its `use` block (sorted) and calls `pest()->extend(TestCase::class)`, so it passes Pint's `fully_qualified_strict_types` and `ordered_imports`. A test without imports keeps the fully qualified form.
- `kit-exports.json` is refreshed from tey/kit 0.4.10, which adds `PageEnd`, `SearchBox` and `StickyBar` to the `@kit/ui` barrel. An app on kit 0.4.10 no longer gets the "installed tey/kit exports differ from the pattern catalog's snapshot" warning from `standards:check`. The development requirement is now tey/kit ^0.4.10.

## 0.11.3 - 2026-10-27

### Upgrading

- Requires tey/kit ^0.4.8 for development (the new `@kit/ui` exports `IndexToolbar` and `BulkBar`, and the server's `TableSelection`). Code generated by `standards:make crud` needs tey/kit 0.4.8 or later in the app.
- `ui-page.index`, `ui-page.form` and `ui-page.show` are now made by `standards:make crud --flavor=resource`. `standards:make page --flavor=index|form|show` still works and still stamps those leaves.

### Added

- `standards:make crud <Record> [--bulk] [--mcp] [--soft-deletes]` emits a complete workspace-owned Inertia CRUD resource on tey/kit:
  - model and migration, with a `lock_version`;
  - a policy that gates on the record's capability (and entitlement) first, as a 404, then on the member's permission;
  - a service provider that registers the policy, capability, permissions, routes and navigation with the kit Registry (and is added to `bootstrap/providers.php`);
  - CRUDDY controllers and `WorkspaceRoutes` routes, form requests, and one Action per write (principal first; an update with a stale `lock_version` is refused with a validation error and the newer record kept);
  - typed ViewModels, placed in the app's first ViewModel root outside its UI roots and extending its configured typed base;
  - Index (DataTable on a TableQuery), Form and Show pages, and server toasts on save;
  - self-contained feature tests that never skip: each migrates the app's test database (SQLite memory by default) and creates its own two workspaces, owners, a viewer and a non-member, and covers owner access, non-member and cross-workspace refusal, viewer read-only, the capability-off 404 and the stale-save refusal.
  - `--bulk` adds selectable rows and a bulk delete whose handler resolves ids with `TableSelection`; `--mcp` adds read and write capability tools; `--soft-deletes` adds soft deletes and a restoration resource.
- `ui-page.data-table` and `ui-page.crud-resource` are ready. `ui-page.index` and `ui-page.data-table` name `IndexToolbar` and `BulkBar`, and `manage-records` starts from `ui-page.crud-resource`.
- `kit-exports.json` is refreshed from tey/kit 0.4.8.

## 0.11.2 - 2026-10-27

### Added

- A `ui-page.toast` leaf (under "A signed-in user of the app") for kit 0.4.6's headless toasts:
  - Toast saves and background results. Errors stay validation or inline. Navigation is never toasted.
  - Styles: pulse with `->from('<action id>')` is the default for saves, anchored is for row actions and inline toggles, fly-out is opt-in, and plain is for background results.
  - Placement is shell-level `kit.toasts.position` with its `auto` resolution order, never set per page.
  - At most one action, never destructive.
  - Components: the shell `Toaster` plus the server `Toast` and `Toastable`.
  - The `manage-records` and `workspace-settings` plans now include it as their save confirmation.
- `kit-exports.json` is refreshed from tey/kit v0.4.6, which adds `Toaster`.

### Changed

- The `toast` rule also accepts a kit headless toast flashed in the method before the redirect: `$this->toast(...)` or `Toast::success|info|warning|error(...)`, outside closures. Chained `->toast*()` still passes, and the check text is unchanged, so allowlist entries keep matching.
- The `page --flavor=form` and `settings-section --flavor=form` stubs use `Toastable`: `$this->toast('Saved.')->from('save')`, then a plain redirect. Generated controllers need tey/kit ^0.4.6. The previous `->toastSuccess()` chain was not a kit API.

## 0.11.1 - 2026-10-27

### Upgrading

- `standards:check` now reports `preset laravel`, `preset security` and `preset php` findings that it silently passed before (see Fixed). If your Pest suite allowlists them already, the same entries cover the gate; otherwise expect new drift that the Pest suite was already reporting.
- The `boost` rule now requires `agents` and `packages` in `boost.json`. Run `php artisan standards:install` to infer whichever is missing, and review them before the next `boost:install` or `boost:update`.
- The default `boostSkills` map now requires `testing-best-practices` for `pestphp/pest` instead of `pest-testing`. Run `php artisan boost:install` (or `boost:update`) to generate it, or override `boostSkills`.

### Fixed

- `standards:check` ran the Pest preset rules as no-ops. Pest only verifies an architecture expectation while a test is running, so outside Pest every preset leaf passed. The gate now stands a test in while it verifies and restores the suite afterwards, and a new parity test runs the same fixture app through the gate and through Pest and expects identical findings.
- `standards:install` honours `core.hooksPath`. It writes the pre-push hook there when the path is inside the repository, and when the path is outside it (a global hooks folder, say) it writes nothing and prints the line to add.
- The `tia` rule accepts a non-empty CI check, `(getenv('CI') === false || getenv('CI') === '')` (or its negation `! (getenv('CI') !== false && getenv('CI') !== '')`), and recommends it, so a set-but-empty `CI=` no longer bypasses the local guard. The older `getenv('CI') === false` is still accepted.
- `standards:install` fills in missing `agents` and `packages` in `boost.json` (agents from the app's agent folders, packages from required packages that ship `resources/boost`) and never changes either once set. Boost 2.10's `boost:update` refuses to run without them, and `boost:install` without `packages` deleted existing project skills.
- The `boost` rule requires `inertia-vue-development` only for `@inertiajs/vue3` 1 and 2 and `tailwindcss-development` only for Tailwind 3 and 4. Boost 2.10.3 ships neither for Inertia 3 or Tailwind 5. A constraint it cannot read still requires the skill.
- Default Boost skills match Boost 2.10.3: it ships `testing-best-practices`, not `pest-testing`. The other defaults were checked against Boost 2.10 (`laravel-best-practices`, `folio-routing`, `wayfinder-development`, `inertia-vue-development` and `tailwindcss-development` ship with Boost) or are shipped by their own packages' `resources/boost/skills` (`fortify-development`, for example).

## 0.11.0 - 2026-10-27

### Upgrading

- `standards:check` needs a `tests/Arch/configuration.php` that returns your `Configuration` (or set `standards.configuration`). It fails closed without one. `standards:install` scaffolds an inferred one and a matching Arch test when it is missing, and never overwrites either.
- Allowlist entries need `expires` (a real `Y-m-d` date) for `standards:check`. Expired entries and entries without one are drift. Pest ratchets still accept entries without `expires`.

### Added

- `standards:check [--changed] [--file=…] [--json]` runs every conformance rule outside Pest, applies the allowlist with expiries, and exits 1 on drift. A class that fails to load is a `load` finding, never a crash. JSON schema 1 carries app, sha, violations, allowlist count and expiries, and pattern gaps.
- A reusable `.github/workflows/standards.yml` workflow to make a required check.
- `standards:install` writes the generated AGENTS.md pattern index, one Boost skill per recipe, path-scoped Claude skills, and merged `.claude/settings.json` hooks. Stop and `git commit` block on drift. New files get an advisory lookup plus a one-time stamp request. Edits get an advisory `--file` check. Every hook is a no-op outside an app that requires tey/standards. It also installs a pre-push hook.
- Pattern lookup in three levels: features (plans composed of unit leaves), the unit decision tree, and kit components per leaf, validated against the kit's `@kit/ui` exports. `Lookup::find(intent|path|answers)` and `recipe(id)` answer with the frozen pattern contract 1 (`docs/pattern-contract.md`), which the hosted recipe MCP builds on. `standards:pattern` is the offline CLI. Every question has a build-your-own exit. Byo and gap leaves return their nearest recipes, conventions and an anonymised demand record, and `standards:check --json` and the drift report count that demand. Leaves carry optional `recipe_make` and count-only `evidence`, and `--gaps` sorts by fleet plus past demand.
- `standards:make` emits stamped, conformant code, with tests that bind the app's TestCase when `tests/Pest.php` doesn't cover their folder. `model`, `policy`, `job`, `command` and `notification` delegate to tey/mod's `make:*` generators and are stamped afterwards. `page`, `capability-tool`, `settings-section` and `action` come from recipe stubs. `bin/standards kit-exports --refresh` regenerates the `@kit/ui` export snapshot, and an installed kit that differs only warns. Every ready leaf is proven in a fixture app.
- `standards report` aggregates several apps' JSON into a drift report with regressions against the previous run.

## 0.10.1 - 2026-10-26

### Fixed

- `install.sessions` reads the resolved `session.driver` and falls back to the environment only when the config has none. On Laravel Cloud it failed every Passport deploy with "SESSION_DRIVER is not set in this environment", even with `SESSION_DRIVER=database` set, because the deploy step doesn't expose the variable to the process. An `array` driver now fails too. (teylabs/standards#4)

## 0.10.0 - 2026-10-26

### Upgrading

- **The non-blocking level is now `advisory`.** JSON results, log context and the table print `"level": "advisory"` where they printed `"warning"`. Update any dashboard or script that matches the old value. `Level::Warning` stays as a deprecated alias of `Level::Advisory`, so custom checks keep working.

### Changed

- A non-interactive `standards:preflight` run (a deploy command, or `--no-interaction`) prints only failed, advisory and emergency-skipped checks, then one summary line. Passing deploys no longer print "warning", which Laravel Cloud flags as "Logs contain warnings". Interactive runs keep the full table. Advisory results print `PREFLIGHT CHECK ADVISORY` on stderr and log at `notice`. (teylabs/standards#3)

## 0.9.0 - 2026-10-26

### Upgrading

- **`install.sessions` blocks deploys.** An app with Passport (or `preflight.sessions.consent` set) must set `SESSION_DRIVER=database` or `redis` in each deployed environment before upgrading, or the deploy preflight fails.

### Added

- Blocking `install.sessions` preflight check: with Passport or a configured MCP consent screen (`preflight.sessions.consent`), a deployed environment fails on a `cookie` session driver or an unset `SESSION_DRIVER`, since OAuth consent outgrows the 4KB cookie. (#345)

## 0.8.1 - 2026-10-24

### Fixed

- Rule 21 no longer counts a descriptor Data class named like an operation (for example a kit `Action` descriptor) as a second operation: suffixes match only longer short names, and classes in a `Data` namespace segment never count (`controllerNonOperationNamespaces`, default `['Data']`). (#302)
- `ui new-tab` accepts configured label components (`uiExternalLabelComponents`), `ui toast` accepts configured renderer files (`uiToastRenderers`), `js-imports` reads static `resolve.alias` keys from `vite.config`, and `providers` skips entries guarded by `class_exists`. Real detection is unchanged. (#302)

## 0.8.0 - 2026-10-24

### Upgrading

- **New architecture rules register automatically.** Apps that loop over `Conformance::TESTS` now run `actions`, `toast`, `mail`, `providers`, `descriptors`, `js-imports`, `pest-only`, `tia`, `boost` and `55`, and rule `21` also checks controller bodies. Run the suite once after upgrading, then fix each finding or baseline it in your allowlist with a dated reason. The `ui plus`, `ui new-tab`, `ui dropzone` and `ui toast` rules stay off unless listed in `Configuration::optionalRules`.

### Added

- Rule 21 checks controller bodies: no inline validation, no helpers beyond response formatting, no direct persistence, one Action or Query per action, and no JSON-versus-redirect forks. Classifiers are configurable. (#271)
- Backend checks: `actions` (no `Operations`-style namespaces; configurable), `toast` (outcomes give Toastable feedback, never session flash keys), `mail` (Notifications only, with an optional composer) and `providers` (`bootstrap/providers.php` lists nothing a no-dev install lacks). (#272, #270)
- Frontend checks: `descriptors` (no role checks or boolean can-maps in pages or responses) and `js-imports` (every bare import is declared), plus the opt-in `ui plus`, `ui new-tab`, `ui dropzone` and `ui toast` conventions. (#274)
- Test tooling checks: `pest-only` (no PHPUnit test classes outside configured bases), `tia` (the local TIA guard in `tests/Pest.php`, with a snippet in the README) and `boost` (Boost's config, guidelines and stack skills). (#273)
- Rule 55 validates `docs/mcp-capabilities.json` schema v1 declarations, with a configurable path and results matching the standalone audit. Rules 63–70 stay manual; the README gives the reason for each. (#275)

## 0.7.0 - 2026-10-20

### Added

- Blocking `install.*` preflight group that proves configured drivers can run: `install.mail` and `install.filesystems` (transport packages and Flysystem adapters, pinning a `resend` mailer without `resend/resend-php` and an `s3` disk without `league/flysystem-aws-s3-v3`), `install.drivers` (redis client, SQS/DynamoDB SDK, memcached), `install.tables` (database-backed session, cache, queue, batch and failed-job tables), `install.extensions` (Composer `ext-*` requirements, image drivers), `install.secrets` (APP_KEY length, Passport PEM keys, placeholder secrets) and `install.vite` (build manifest). Values are never printed; packages that are not apps skip the group.
- Limit rule 54 to deployed applications; `nightwatch.ready` skips packages without warnings using the shared rule-51 distribution detection, including strict deploy/CI coverage. (#172)
- Warning-level `nightwatch.ready` checks the runtime dependency, published config and sampling rates without a token in deploy and CI modes; failures block only with `--strict`. (#172)
- Shared manifest page-scaffold reader and consistent directory/component casing rules for the standalone audit and Arch preset. (#171)

## 0.6.1 - 2026-10-13

### Added

- Shared `drafts: lint|full` across `CiTriggers`, `Configuration::ciDrafts`, app config/env and the package CLI/Composer `extra`, defaulting to `lint`. Public repos may opt out of draft guards, draft-aware concurrency and the `ready_for_review` requirement; every other check, including ready-PR Windows coverage, remains. Read visibility from the GitHub event payload offline: private repos retain lint requirements with `drafts-full-private`, unknown visibility honours full with an advisory, invalid config/env values become requirements and invalid CLI flags exit 2. Parser, entry-point, precedence and production-only smoke tests cover the contract. (#163)

### Changed

- Packages run the CLI in a separate global-tool job (`composer global require tey/standards:^0.6` on PHP 8.4), not as a dev dependency, which breaks package matrices on older PHP or Laravel. The README's Packages section has the job. (#163)


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
