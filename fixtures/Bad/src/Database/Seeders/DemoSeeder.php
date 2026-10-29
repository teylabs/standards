<?php

declare(strict_types=1);

namespace FixtureBad\Database\Seeders;

// Fake data: rand() is normal in a seeder, so preset security leaves seeder roots out (Configuration::$securityExemptRoots).
final class DemoSeeder
{
    public function run(): int
    {
        return rand(1, 9);
    }
}
