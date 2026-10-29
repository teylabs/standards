<?php

namespace FixtureUnserialize\Safe;

final class NamedOptions
{
    public function read(string $data, array $options, array $classes): mixed
    {
        return unserialize($data, options: ['allowed_classes' => []]);
    }
}
