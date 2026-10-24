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
     * @param  list<string>  $controllerOperationSuffixes
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
    ) {}
}
