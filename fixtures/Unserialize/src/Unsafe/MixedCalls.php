<?php

namespace FixtureUnserialize\Unsafe;

final class MixedCalls
{
    public function read(string $data, array $options, array $classes): mixed
    {
        return [unserialize($data, ['allowed_classes' => false]), UNSERIALIZE($data)];
    }
}
