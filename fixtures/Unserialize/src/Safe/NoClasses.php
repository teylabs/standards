<?php

namespace FixtureUnserialize\Safe;

final class NoClasses
{
    public function read(string $data, array $options, array $classes): mixed
    {
        return unserialize($data, ['allowed_classes' => false, 'max_depth' => 8]);
    }
}
