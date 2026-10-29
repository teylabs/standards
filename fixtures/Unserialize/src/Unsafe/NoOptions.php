<?php

namespace FixtureUnserialize\Unsafe;

final class NoOptions
{
    public function read(string $data, array $options, array $classes): mixed
    {
        return unserialize($data);
    }
}
