<?php

namespace FixtureUnserialize\Unsafe;

final class VariableOptions
{
    public function read(string $data, array $options, array $classes): mixed
    {
        return unserialize($data, $options);
    }
}
