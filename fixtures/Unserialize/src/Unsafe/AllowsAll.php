<?php

namespace FixtureUnserialize\Unsafe;

final class AllowsAll
{
    public function read(string $data, array $options, array $classes): mixed
    {
        return unserialize($data, ['allowed_classes' => true]);
    }
}
