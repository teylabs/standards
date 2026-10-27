<?php

namespace App\UI;

abstract class ViewModel
{
    /** @return array<string, mixed> */
    abstract public function toArray(): array;
}
