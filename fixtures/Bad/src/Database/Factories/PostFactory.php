<?php

declare(strict_types=1);

namespace FixtureBad\Database\Factories;

// Fake data: mt_rand() is normal in a factory, so preset security leaves factory roots out (Configuration::$securityExemptRoots).
final class PostFactory
{
    public function definition(): int
    {
        return mt_rand(1, 9);
    }
}
