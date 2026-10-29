<?php

namespace App\Pdf;

final class InvoiceRenderer
{
    public function render(string $html): string
    {
        $pdf = new \Mpdf\Mpdf(['format' => 'A4']);
        $pdf->WriteHTML($html);

        return $pdf->Output('', 'S');
    }
}
