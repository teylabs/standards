<?php

namespace FixtureBad\UI;

use Pest\Expectation;

class Tool
{
    public function __construct(public Expectation $expectation) {}
}
