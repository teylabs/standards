<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use JsonException;
use stdClass;
use Tey\Standards\Configuration;

final readonly class Boost
{
    /** Package => majors Boost 2.10.3 ships its default skill for. */
    private const VERSIONED = [
        '@inertiajs/vue3' => [1, 2],
        'tailwindcss' => [3, 4],
    ];

    public function __construct(private Configuration $config) {}

    /** @return list<array{target: string, check: string}> */
    public function violations(): array
    {
        $composer = $this->manifest('composer.json');
        if (! isset($composer->{'require-dev'}->{'laravel/boost'})) {
            return [['target' => 'composer.json', 'check' => 'laravel/boost must be declared in require-dev']];
        }

        $violations = [];
        $boost = $this->manifest('boost.json');
        if ($boost === null) {
            $violations[] = ['target' => 'boost.json', 'check' => 'Boost configuration must be a readable JSON object'];
        } elseif (! is_array($boost->agents ?? null) || $boost->agents === [] || ! is_array($boost->packages ?? null)) {
            // boost:update refuses to run without agents; boost:install without packages deletes project skills.
            $violations[] = ['target' => 'boost.json', 'check' => 'boost.json must list "agents" and "packages" before boost:install or boost:update (run php artisan standards:install to infer them)'];
        }
        if (! is_dir($this->config->base.'/.ai/guidelines')) {
            $violations[] = ['target' => '.ai/guidelines', 'check' => 'Boost guidelines directory is missing'];
        }

        $guidance = false;
        foreach (['AGENTS.md', 'CLAUDE.md'] as $path) {
            $text = is_file($this->config->base.'/'.$path) ? file_get_contents($this->config->base.'/'.$path) : '';
            $guidance = $guidance || preg_match('/<laravel-boost-guidelines>\s*\S[\s\S]*?<\/laravel-boost-guidelines>/', (string) $text) === 1;
        }
        if (! $guidance) {
            $violations[] = ['target' => 'AGENTS.md', 'check' => 'generated Boost guideline block is missing from AGENTS.md and CLAUDE.md'];
        }

        $npm = $this->manifest('package.json');
        foreach ($this->config->boostSkills as $package => $skills) {
            if (! isset($composer->require->{$package}) && ! isset($composer->{'require-dev'}->{$package})
                && ! isset($npm->dependencies->{$package}) && ! isset($npm->devDependencies->{$package})) {
                continue;
            }
            if (! $this->shipsFor($package, $composer, $npm)) {
                continue;
            }
            foreach ($skills as $skill) {
                $present = array_any($this->config->boostSkillRoots, fn (string $root): bool => is_file($this->config->base.'/'.$root.'/'.$skill.'/SKILL.md')
                    && trim((string) file_get_contents($this->config->base.'/'.$root.'/'.$skill.'/SKILL.md')) !== '');
                if (! $present) {
                    $violations[] = ['target' => 'boost.json', 'check' => 'missing Boost skill '.$skill.' for '.$package];
                }
            }
        }

        return $violations;
    }

    /**
     * Boost 2.10 ships some stack skills only for certain majors: inertia-vue-development
     * for Inertia 1 and 2, tailwindcss-development for Tailwind 3 and 4. A declared
     * constraint on another major requires nothing; one that cannot be read still does.
     */
    private function shipsFor(string $package, ?stdClass $composer, ?stdClass $npm): bool
    {
        $majors = self::VERSIONED[$package] ?? null;
        if ($majors === null) {
            return true;
        }

        $constraint = $composer->require->{$package} ?? $composer->{'require-dev'}->{$package} ?? $npm->dependencies->{$package} ?? $npm->devDependencies->{$package} ?? null;

        return ! is_string($constraint) || preg_match('/(\d+)/', $constraint, $match) !== 1 || in_array((int) $match[1], $majors, true);
    }

    private function manifest(string $path): ?stdClass
    {
        if (! is_file($this->config->base.'/'.$path) || ! is_readable($this->config->base.'/'.$path)) {
            return null;
        }
        try {
            $value = json_decode((string) file_get_contents($this->config->base.'/'.$path), false, 512, JSON_THROW_ON_ERROR);

            return $value instanceof stdClass ? $value : null;
        } catch (JsonException) {
            return null;
        }
    }
}
