<?php

namespace App\Pdf;

use Mpdf\Mpdf as Engine;

final class ReportRenderer
{
    public function portrait(): Engine
    {
        return new Engine(['orientation' => 'P']);
    }
}
