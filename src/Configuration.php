<?php

declare(strict_types=1);

namespace Tey\Standards;

/**
 * Paths are project-relative directory globs; namespaces have no trailing slash.
 */
final readonly class Configuration
{
    /**
     * @param  array<string, string>  $classRoots  path => PSR-4 namespace
     * @param  list<string>  $sourceRoots
     * @param  list<string>  $uiRoots
     * @param  list<string>  $uiNamespaces
     * @param  list<string>  $hostNamespaces
     * @param  list<string>  $controllerRoots
     * @param  list<string>  $routeRoots
     * @param  list<string>  $viewModelRoots
     * @param  list<string>  $viewModelBases  typed base and compatibility bases, not exemptions
     * @param  string  $typedViewModel  the base every ViewModel extends; '' for none (rule 20 then finds ViewModels by suffix and requires no base)
     * @param  list<string>  $pageRoots
     * @param  list<string>  $pageScaffolds
     * @param  array<string, list<string>>  $placements  class suffix => allowed directories
     * @param  array<string, list<string>>  $retired  retired namespace => its own directories
     * @param  list<string>  $discoveryRoots
     * @param  list<string>  $developmentNamespaces
     * @param  list<string>  $safeAttributes
     * @param  list<array{rule: string, target: string, check: string, reason: string, date: string}>  $allowlist
     * @param  list<string>  $forbiddenNamespaces  full namespace prefixes or exact namespace segments
     * @param  list<string>  $controllerOperationNamespaces  namespace segments or qualified namespace paths
     * @param  list<string>  $controllerOperationSuffixes  matched on the short class name, which must be longer than the suffix
     * @param  list<string>  $controllerNonOperationNamespaces  namespace segments that are never operations (descriptor Data classes)
     * @param  list<string>|null  $frontendRoots  null scans existing resources/js and UI roots
     * @param  list<string>  $frontendAliases  local import prefixes
     * @param  list<string>|null  $descriptorResponseRoots  null uses sourceRoots
     * @param  list<string>  $optionalRules
     * @param  list<string>  $uiActionComponents
     * @param  list<string>  $uiPlusIcons
     * @param  list<string>  $uiExternalIcons
     * @param  list<string>  $uiExternalLabelComponents  components that render the external icon themselves
     * @param  list<string>  $uiToastRenderers  project-relative files allowed to import the toast library
     * @param  list<string>  $testBasePaths
     * @param  list<string>  $testRoots  where the test files live, for the php-tag rule (globs allowed)
     * @param  list<string>  $versionedModels  for rule `versioned-writes`: traits or classes that make a model versioned (stale-write guarded), beyond the default detection of a trait named Versioned, HasVersion or OptimisticLock
     * @param  string  $versionColumn  the column a versioned model bumps on every write
     * @param  list<string>  $tenantKeys  the column a single-database app scopes tenants by, for rule `tenant-aware`'s default detection (a model with it in $fillable or in its table's migration is tenant-scoped)
     * @param  list<string>  $tenantModels  what makes a model tenant-scoped for rule `tenant-aware`: traits it uses or classes it is (or extends). Default: spatie/laravel-multitenancy's UsesTenantConnection; add the app's own tenant-scope trait or base model
     * @param  list<string>  $presetIgnores  namespaces the stock Laravel preset does not apply to: a modular app's module roots (App\\Modules), whose own placement rule 20 and the tey/mod layout enforce
     * @param  array<string, list<string>>  $boostSkills  Composer or npm package => required skills
     * @param  list<string>  $boostSkillRoots
     * @param  list<string>  $ciDefaultBranches
     */
    public function __construct(
        public string $base,
        public array $classRoots,
        public array $sourceRoots,
        public array $uiRoots,
        public array $uiNamespaces,
        public array $hostNamespaces,
        public array $controllerRoots,
        public array $routeRoots,
        public string $controllerSuffix,
        public string $routeDirectory,
        public array $viewModelRoots,
        public array $viewModelBases,
        public string $typedViewModel,
        public string $viewModelSuffix,
        public array $pageRoots,
        public array $pageScaffolds,
        public array $placements,
        public array $retired,
        public array $discoveryRoots,
        public array $developmentNamespaces,
        public array $safeAttributes,
        public string $mediaTrait,
        public string $mediaInterface,
        public array $allowlist = [],
        public array $ciDefaultBranches = ['main'],
        public ?string $ciDistribution = null,
        public bool $ciEnforce = false,
        public mixed $ciDrafts = 'lint',
        public array $forbiddenNamespaces = ['Operations'],
        public ?string $mailComposer = null,
        public ?string $controllerResponseHelperPattern = null,
        public array $controllerOperationNamespaces = ['Actions', 'Queries'],
        public array $controllerOperationSuffixes = ['Action', 'Query'],
        public ?array $frontendRoots = null,
        public array $frontendAliases = ['@/', '~/'],
        public ?array $descriptorResponseRoots = null,
        public array $optionalRules = [],
        public array $uiActionComponents = ['Button', 'button', 'ModalLink', 'Link', 'a', 'DropdownMenuItem'],
        public array $uiPlusIcons = ['Plus', 'PlusIcon'],
        public array $uiExternalIcons = ['ArrowUpRight', 'ArrowUpRightIcon', 'ExternalLink', 'ExternalLinkIcon'],
        public string $uiDropzone = 'app/UI/resources/js/Form/InputFile.vue',
        public string $uiToastLibrary = 'vue-sonner',
        public string $uiToastModule = 'app/UI/resources/js/Notification/NotificationCenter.vue',
        public string $uiNotificationCenter = 'app/UI/resources/js/Notification/NotificationCenter.vue',
        public array $testBasePaths = ['tests/TestCase.php'],
        public array $testRoots = ['tests'],
        public array $presetIgnores = [],
        public array $tenantKeys = ['workspace_id', 'tenant_id'],
        public array $versionedModels = [],
        public string $versionColumn = 'version',
        public array $tenantModels = ['Spatie\\Multitenancy\\Models\\Concerns\\UsesTenantConnection'],
        public array $boostSkills = [
            'laravel/framework' => ['laravel-best-practices'],
            'pestphp/pest' => ['testing-best-practices'],
            'laravel/folio' => ['folio-routing'],
            'laravel/fortify' => ['fortify-development'],
            'laravel/wayfinder' => ['wayfinder-development'],
            'tey/mod' => ['mod-development', 'mod-learning-patterns'],
            'inertiaui/modal' => ['inertia-modal-development'],
            'lorisleiva/laravel-actions' => ['laravel-actions'],
            'spatie/laravel-medialibrary' => ['medialibrary-development'],
            'spatie/laravel-permission' => ['laravel-permission-development'],
            'spatie/laravel-query-builder' => ['laravel-query-builder'],
            '@inertiajs/vue3' => ['inertia-vue-development'],
            'tailwindcss' => ['tailwindcss-development'],
        ],
        public array $boostSkillRoots = ['.claude/skills', '.agents/skills', '.codex/skills'],
        public string $mcpManifest = 'docs/mcp-capabilities.json',
        public array $uiExternalLabelComponents = [],
        public array $uiToastRenderers = [],
        public array $controllerNonOperationNamespaces = ['Data'],
        /** @var list<string> classes that fail to load; set by the gate's load probe and skipped by the checks */
        public array $unloadable = [],
    ) {}
}
