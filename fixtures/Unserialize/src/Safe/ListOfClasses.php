<?php

namespace FixtureUnserialize\Safe;

final class ListOfClasses
{
    public function read(string $data, array $options, array $classes): mixed
    {
        return unserialize($data, ['allowed_classes' => [\DateTimeImmutable::class, \stdClass::class]]);
    }
}
