<?php

declare(strict_types=1);

namespace Tey\Standards;

use Illuminate\Container\Container;
use Illuminate\Contracts\Container\Container as ContainerContract;
use InvalidArgumentException;
use Tey\Standards\Preflight\Check;

final class Preflight
{
    public const TAG = 'standards.preflight';

    /** @param class-string<Check> $check */
    public static function register(string $check): void
    {
        Container::getInstance()->tag([$check], self::TAG);
    }

    /** @return array<string, Check> */
    public function checks(ContainerContract $container): array
    {
        $checks = [];

        foreach ($container->tagged(self::TAG) as $check) {
            if (! $check instanceof Check || ! preg_match('/\A[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*\z/', $check->name())) {
                throw new InvalidArgumentException('Preflight checks must implement Check and have a lowercase dotted name.');
            }

            if (isset($checks[$check->name()])) {
                throw new InvalidArgumentException('Duplicate preflight check: '.$check->name());
            }

            $checks[$check->name()] = $check;
        }

        return $checks;
    }
}
