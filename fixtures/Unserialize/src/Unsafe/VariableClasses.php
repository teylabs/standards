<?php

namespace FixtureUnserialize\Unsafe;

final class VariableClasses
{
    public function read(string $data, array $options, array $classes): mixed
    {
        return unserialize($data, ['allowed_classes' => $classes]);
    }
}
