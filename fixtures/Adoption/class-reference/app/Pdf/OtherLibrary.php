<?php

namespace App\Pdf;

use Mpdf\Config\ConfigVariables;

// Mpdf\Mpdf in a comment is not a reference; neither is a class that only shares the prefix or the short name.
final class OtherLibrary
{
    public function defaults(): array
    {
        $this->Mpdf = null;

        return (new ConfigVariables)->getDefaults() + [\Acme\Mpdf\Mpdf::class => true];
    }
}
