<?php

namespace App\UI;

final class Link
{
    public function __construct(public string $url) {}

    public function read(): string
    {
        return $this->url;
    }
}
