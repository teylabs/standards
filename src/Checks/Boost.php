<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use JsonException;
use stdClass;
use Tey\Standards\Configuration;

final readonly class Boost
{
    public function __construct(private Configuration $config) {}

    /** @return list<array{target: string, check: string}> */
    public function violations(): array
    {
        $composer = $this->manifest('composer.json');
        if (! isset($composer->{'require-dev'}->{'laravel/boost'})) {
            return [['target' => 'composer.json', 'check' => 'laravel/boost must be declared in require-dev']];
        }

        $violations = [];
        if ($this->manifest('boost.json') === null) {
            $violations[] = ['target' => 'boost.json', 'check' => 'Boost configuration must be a readable JSON object'];
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
