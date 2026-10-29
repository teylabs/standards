<?php

namespace FixtureUnserialize\Unsafe;

final class OtherKeyOnly
{
    public function read(string $data, array $options, array $classes): mixed
    {
        return unserialize($data, ['max_depth' => 8]);
    }
}
