<?php

namespace Mpdf;

final class Factory
{
    public function make(): \Mpdf\Mpdf
    {
        return new \Mpdf\Mpdf;
    }
}
