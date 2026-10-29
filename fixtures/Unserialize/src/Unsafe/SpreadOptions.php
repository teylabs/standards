<?php

namespace FixtureUnserialize\Unsafe;

final class SpreadOptions
{
    public function read(string $data, array $options, array $classes): mixed
    {
        return unserialize($data, [...$options, 'allowed_classes' => false]);
    }
}
