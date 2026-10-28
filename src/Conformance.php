<?php

declare(strict_types=1);

namespace Tey\Standards;

use Composer\InstalledVersions;
use InvalidArgumentException;
use LogicException;
use Pest\Arch\Contracts\ArchExpectation;
use Pest\ArchPresets\Laravel;
use Pest\ArchPresets\Php;
use Pest\ArchPresets\Security;
use Pest\TestSuite;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Assert;
use ReflectionClass;

final class Conformance
{
    public const TESTS = [
        'actions' => '[actions] backend units use configured Action namespaces',
        'toast' => '[toast] outcomes use Toastable feedback',
        'mail' => '[mail] mail uses notifications and the configured composer',
        'providers' => '[providers] bootstrap providers survive a no-dev install',
        'descriptors' => '[descriptors] UI consumes descriptors without authorization checks',
        'js-imports' => '[js-imports] frontend imports name declared packages',
        'ui plus' => '[ui plus] add actions carry a plus icon',
        'ui new-tab' => '[ui new-tab] new-tab actions carry noopener and an external icon',
        'ui dropzone' => '[ui dropzone] only the configured dropzone owns file inputs',
        'ui toast' => '[ui toast] notifications use one configured toast path',
        'operator' => '[operator] commands that write offer a dry run and a production guard',
        'integrations' => '[integrations] webhooks verify their sender and API calls go through a contract',

        '14' => '[rule 14] UI does not build host routes',
        '22' => '[rule 22] UI has no host PHP dependencies',
        '20' => '[rule 20] classes, controllers, pages and typed ViewModels follow configured placement',
        '21' => '[rule 21] controllers and routes are CRUDDY',
        '19' => '[rule 19] media models implement HasMedia and conversions avoid getPath',
        '29' => '[rule 29] no Fortify authentication callback',
        '32' => '[rule 32] capabilities and policies follow configured placement',
        '12' => '[rule 12] live PHP does not reference retired namespaces',
        '51' => '[rule 51] CI triggers conserve minutes without cancelling main',
        'pest-only' => '[pest-only] tests use Pest instead of PHPUnit test classes',
        'php-tag' => '[php-tag] PHP files under the test and source roots open with <?php',
        'tia' => '[tia] local test runs require an explicit TIA choice',
        'boost' => '[boost] stack guidance and skills are present',
        '55' => '[rule 55] MCP capability declarations map UI, shared operations and parity tests',
        'no-dev' => '[no-dev boot] discovery roots do not depend on development tooling',
        'preset laravel' => '[preset laravel] stock Laravel conventions',
        'preset security' => '[preset security] no insecure PHP functions',
        'preset php' => '[preset php] no debugging or deprecated PHP functions',
    ];

    private readonly Source $source;

    private readonly Ratchet $ratchet;

    public function __construct(private readonly Configuration $config)
    {
        if (! class_exists(TestSuite::class) || ! interface_exists(ArchExpectation::class) || ! class_exists(ParserFactory::class)) {
            throw new LogicException('Architecture checks require pestphp/pest ^4.7 || ^5.0, pestphp/pest-plugin-arch ^4.0 || ^5.0 and nikic/php-parser ^5.0 in require-dev.');
        }

        self::assertSupportedPest(InstalledVersions::isInstalled('pestphp/pest') ? InstalledVersions::getPrettyVersion('pestphp/pest') : null);

        $this->source = new Source($config);
        $this->ratchet = new Ratchet($config->allowlist);
        $this->ratchet->assertRules(array_map('strval', array_keys(self::TESTS)));
    }

    /**
     * The architecture adapter reads reviewed Pest 4 and 5 internals. Other
     * majors may coexist with the deploy preflight, which never loads Pest.
     */
    public static function assertSupportedPest(?string $version): void
    {
        $major = $version === null ? null : (int) ltrim($version, 'v');

        if ($major !== null && (! in_array($major, [4, 5], true) || ($major === 4 && version_compare(ltrim($version, 'v'), '4.7.0', '<')))) {
            throw new LogicException("tey/standards architecture checks support Pest 4 (4.7+) and Pest 5; Pest {$version} is installed. The deploy preflight is unaffected.");
        }
    }

    public function assert(string $rule): void
    {
        if (in_array($rule, ['ui plus', 'ui new-tab', 'ui dropzone', 'ui toast'], true) && ! in_array($rule, $this->config->optionalRules, true)) {
            Assert::markTestSkipped('Optional rule ['.$rule.'] is disabled; enable it in Configuration::optionalRules.');
        }

        $violations = $this->violations($rule);

        if ($rule === '51' && ! $this->config->ciEnforce) {
            Assert::markTestSkipped('Rule 51 is advisory until PREFLIGHT_CI_ENFORCE=true; run `php artisan standards:preflight --ci --only=ci` to see findings ('.count($violations).' findings).');
        }

        // Advisory until an app opts in: static write detection can miss a write or flag a non-write.
        if (in_array($rule, ['operator', 'integrations'], true) && ! in_array($rule, $this->config->optionalRules, true)) {
            Assert::markTestSkipped('Rule ['.$rule.'] is advisory until listed in Configuration::optionalRules ('.count($violations).' findings).');
        }

        $failures = $this->ratchet->failures($rule, $violations);
        expect($failures)->toBe([], implode("\n", $failures));
    }

    /** @return list<array{target: string, check: string}> */
    public function violations(string $rule): array
    {
        if (in_array($rule, ['ui plus', 'ui new-tab', 'ui dropzone', 'ui toast'], true) && ! in_array($rule, $this->config->optionalRules, true)) {
            return [];
        }

        $frontend = match ($rule) {
            'descriptors' => (new Checks\Descriptors)->violations($this->config),
            'js-imports' => (new Checks\JsImports)->violations($this->config),
            'ui plus' => (new Checks\UiPlus)->violations($this->config),
            'ui new-tab' => (new Checks\UiNewTab)->violations($this->config),
            'ui dropzone' => (new Checks\UiDropzone)->violations($this->config),
            'ui toast' => (new Checks\UiToast)->violations($this->config),
            default => null,
        };
        if ($frontend !== null) {
            return $frontend;
        }

        if ($rule === '51') {
            return array_map(fn (array $finding): array => ['target' => $finding['workflow'], 'check' => $finding['job'].' | '.$finding['reason']],
                (new CiTriggers($this->config->base, $this->config->ciDefaultBranches, $this->config->ciDistribution, $this->config->ciDrafts))->findings());
        }

        if (in_array($rule, ['actions', 'toast', 'mail', 'providers', 'operator', 'integrations'], true)) {
            $check = match ($rule) {
                'integrations' => new Checks\Integrations($this->config, $this->source),
                'operator' => new Checks\OperatorCommands($this->config, $this->source),
                'actions' => new Checks\Actions($this->config, $this->source),
                'toast' => new Checks\Toast($this->config, $this->source),
                'mail' => new Checks\Mail($this->config, $this->source),
                'providers' => new Checks\Providers($this->config, $this->source),
            };

            return $check->violations();
        }

        if (in_array($rule, ['pest-only', 'php-tag', 'tia', 'boost'], true)) {
            return match ($rule) {
                'pest-only' => (new Checks\PestOnly($this->config))->violations(),
                'php-tag' => (new Checks\PhpTag($this->config))->violations(),
                'tia' => (new Checks\Tia($this->config))->violations(),
                'boost' => (new Checks\Boost($this->config))->violations(),
            };
        }

        if ($rule === '55') {
            return (new Checks\McpParity($this->config->base, $this->config->mcpManifest))->violations();
        }

        $classes = $this->source->classes();

        return match ($rule) {
            '14' => $this->routes(),
            '22' => $this->dependencies($this->config->uiRoots, $this->config->hostNamespaces, $this->config->uiNamespaces),
            '20' => $this->placement($classes),
            '21' => [...$this->cruddy(), ...(new Checks\ControllerBodies($this->config, $this->source))->violations()],
            '19' => $this->media($classes),
            '29' => $this->fortify(),
            '32' => $this->roles($classes),
            '12' => $this->retired(),
            'no-dev' => $this->dependencies($this->config->discoveryRoots, $this->config->developmentNamespaces, $this->config->safeAttributes, attributesOnly: true),
            'preset laravel', 'preset security', 'preset php' => $this->preset($rule, $classes),
            default => throw new InvalidArgumentException("Unknown conformance rule [{$rule}]."),
        };
    }

    /** @param list<string> $roots
     * @param  list<string>  $banned
     * @param  list<string>  $allowed
     * @return list<array{target: string, check: string}>
     */
    private function dependencies(array $roots, array $banned, array $allowed = [], bool $attributesOnly = false): array
    {
        $violations = [];

        foreach ($this->source->files($roots) as $path => $text) {
            foreach ((new NodeFinder)->findInstanceOf($this->source->nodes($path), Node\Name::class) as $name) {
                $dependency = $name->toString();
                $parent = $name->getAttribute('parent');
                $permitted = $attributesOnly
                    ? in_array($dependency, $allowed, true) && ($parent instanceof Node\Attribute || $parent instanceof Node\UseItem)
                    : Source::within($dependency, $allowed);

                if (Source::within($dependency, $banned) && ! $permitted) {
                    $violations[] = ['target' => $path, 'check' => 'depends on '.$dependency];
                }
            }
        }

        return array_values(array_unique($violations, SORT_REGULAR));
    }

    /** @return list<array{target: string, check: string}> */
    private function routes(): array
    {
        $violations = [];

        foreach ($this->source->files($this->config->uiRoots) as $path => $text) {
            foreach ((new NodeFinder)->find($this->source->nodes($path), fn (Node $node): bool => $node instanceof Node\Expr\FuncCall || $node instanceof Node\Expr\StaticCall) as $call) {
                $route = $call instanceof Node\Expr\FuncCall
                    ? $call->name instanceof Node\Name && in_array($call->name->toString(), ['route', 'to_route'], true)
                    : $call instanceof Node\Expr\StaticCall && $call->class instanceof Node\Name && $call->class->toString() === 'Illuminate\\Support\\Facades\\Route';

                if ($route) {
                    $violations[] = ['target' => $path, 'check' => 'builds a host route'];
                }
            }
        }

        return array_values(array_unique($violations, SORT_REGULAR));
    }

    /** @param array<class-string, string> $classes
     * @return list<array{target: string, check: string}>
     */
    private function placement(array $classes): array
    {
        $violations = [];

        foreach ($classes as $class => $path) {
            $reflection = new ReflectionClass($class);
            $expected = [];

            foreach ($this->config->classRoots as $root => $namespace) {
                if (Source::within($class, [$namespace])) {
                    $expected[] = $root.'/'.str_replace('\\', '/', substr($class, strlen($namespace) + 1)).'.php';
                }
            }

            if (! in_array($path, $expected, true)) {
                $violations[] = ['target' => $path, 'check' => 'class does not match configured PSR-4 placement'];
            }

            if (str_ends_with($class, $this->config->controllerSuffix) && ! $this->source->inside($path, $this->config->controllerRoots)) {
                $violations[] = ['target' => $class, 'check' => 'controller outside configured Http controllers'];
            }

            $typed = $this->config->typedViewModel;
            $viewModel = str_ends_with($class, $this->config->viewModelSuffix) || ($typed !== '' && $reflection->isSubclassOf($typed));

            if (! $viewModel || in_array($class, $this->config->viewModelBases, true)) {
                continue;
            }

            if (! $this->source->inside($path, $this->config->viewModelRoots)) {
                $violations[] = ['target' => $class, 'check' => 'ViewModel outside configured roots'];
            }

            foreach (Native::failures(expect($class)->toHaveSuffix($this->config->viewModelSuffix), $class) as $failure) {
                $violations[] = ['target' => $class, 'check' => 'ViewModel needs configured suffix'];
            }

            foreach ($typed === '' ? [] : Native::failures(expect($class)->toExtend($typed), $class) as $failure) {
                $violations[] = ['target' => $class, 'check' => 'ViewModel must extend typed base'];
            }
        }

        foreach ($this->source->files($this->config->pageRoots, '*.vue') as $path => $text) {
            if (! PageConventions::validPath($path, $this->config->pageScaffolds)) {
                $violations[] = ['target' => $path, 'check' => 'page path must use PascalCase except configured scaffolds'];
            }
        }

        return $violations;
    }

    /** @return list<array{target: string, check: string}> */
    private function cruddy(): array
    {
        $report = (new CruddyGuard(
            $this->config->base, $this->config->controllerRoots, $this->config->routeRoots,
            searchRoots: array_keys($this->config->classRoots), controllerSuffix: $this->config->controllerSuffix,
            routeDirectory: $this->config->routeDirectory,
        ))->run();

        if (! $report['verified']) {
            throw new InvalidArgumentException('CRUDDY roots reach no controllers or routes: not verified.');
        }

        return array_values(array_map(fn (array $violation): array => ['target' => $violation['file'], 'check' => $violation['kind'].' | '.$violation['subject']], $report['violations']));
    }

    /** @param array<class-string, string> $classes
     * @return list<array{target: string, check: string}>
     */
    private function media(array $classes): array
    {
        $violations = [];

        foreach ($classes as $class => $path) {
            $traits = [];

            for ($reflection = new ReflectionClass($class); $reflection !== false; $reflection = $reflection->getParentClass()) {
                $traits = [...$traits, ...$reflection->getTraitNames()];
            }

            for ($index = 0; $index < count($traits); $index++) {
                $traits = array_values(array_unique([...$traits, ...(new ReflectionClass($traits[$index]))->getTraitNames()]));
            }

            if (in_array($this->config->mediaTrait, $traits, true)) {
                foreach (Native::failures(expect($class)->toImplement($this->config->mediaInterface), $class) as $failure) {
                    $violations[] = ['target' => $class, 'check' => 'media model must implement HasMedia'];
                }
            }
        }

        foreach ($this->source->files($this->config->sourceRoots) as $path => $text) {
            foreach ((new NodeFinder)->find($this->source->nodes($path), fn (Node $node): bool => $node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall) as $call) {
                if (($call instanceof Node\Expr\MethodCall || $call instanceof Node\Expr\NullsafeMethodCall)
                    && $call->name instanceof Node\Identifier && strtolower($call->name->toString()) === 'getpath') {
                    $violations[] = ['target' => $path, 'check' => 'getPath call: measure conversions through the disk'];
                }
            }
        }

        return array_values(array_unique($violations, SORT_REGULAR));
    }

    /** @return list<array{target: string, check: string}> */
    private function fortify(): array
    {
        $violations = [];

        foreach ($this->source->files($this->config->sourceRoots) as $path => $text) {
            foreach ((new NodeFinder)->findInstanceOf($this->source->nodes($path), Node\Expr\StaticCall::class) as $call) {
                if ($call->class instanceof Node\Name && $call->class->toString() === 'Laravel\\Fortify\\Fortify'
                    && $call->name instanceof Node\Identifier && strtolower($call->name->toString()) === 'authenticateusing') {
                    $violations[] = ['target' => $path, 'check' => 'Fortify::authenticateUsing is forbidden'];
                }
            }
        }

        return array_values(array_unique($violations, SORT_REGULAR));
    }

    /** @param array<string, string> $classes
     * @return list<array{target: string, check: string}>
     */
    private function roles(array $classes): array
    {
        $violations = [];

        foreach ($classes as $class => $path) {
            foreach ($this->config->placements as $suffix => $roots) {
                if (str_ends_with($class, $suffix) && ! $this->source->inside($path, $roots)) {
                    $violations[] = ['target' => $class, 'check' => $suffix.' outside configured placement'];
                }
            }
        }

        return $violations;
    }

    /** @return list<array{target: string, check: string}> */
    private function retired(): array
    {
        $violations = [];

        foreach ($this->config->retired as $namespace => $ownRoots) {
            foreach ($this->dependencies($this->config->sourceRoots, [$namespace]) as $violation) {
                if (! $this->source->inside($violation['target'], $ownRoots)) {
                    $violations[] = $violation;
                }
            }
        }

        return $violations;
    }

    /** @param array<string, string> $classes
     * @return list<array{target: string, check: string}>
     */
    private function preset(string $rule, array $classes): array
    {
        $type = match ($rule) {
            'preset laravel' => Laravel::class,
            'preset security' => Security::class,
            'preset php' => Php::class,
            default => throw new InvalidArgumentException("Unknown conformance preset [{$rule}]."),
        };
        $paths = [];

        foreach ($classes as $class => $path) {
            $paths[realpath($this->config->base.'/'.$path) ?: $this->config->base.'/'.$path] = $class;
        }

        return Native::preset($type, array_values($this->config->classRoots), $paths);
    }
}
