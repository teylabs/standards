<?php

namespace App\Pdf;

use Barryvdh\DomPDF\Facade\Pdf;

final class StatementRenderer
{
    public function render(array $data): string
    {
        return Pdf::loadView('pdf.statement', $data)->output();
    }
}
