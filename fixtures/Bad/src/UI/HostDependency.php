<?php

namespace FixtureBad\UI;

use FixtureBad\Retired\Thing as HostThing;

class HostDependency
{
    public function __construct(public HostThing $thing) {}
}
