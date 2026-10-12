<?php

namespace FixtureBad\UI;

use FixtureGood\UI\SafeAttribute;

class AttributeRuntime
{
    public function instance(): object
    {
        return new SafeAttribute;
    }
}
