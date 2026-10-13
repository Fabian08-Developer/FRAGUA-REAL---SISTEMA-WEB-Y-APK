<?php

namespace App\Support\Documentos;

use Barryvdh\DomPDF\PDF;

/**
 * "Página n de m" al pie de un PDF de varias páginas (cierre diario, reportes). dompdf solo sabe
 * el total de páginas después de dibujar todo, así que se escribe sobre el lienzo ya dibujado.
 */
final class PiePagina
{
    public static function numerar(PDF $pdf): PDF
    {
        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $lienzo = $dompdf->getCanvas();
        $lienzo->page_text($lienzo->get_width() - 100, $lienzo->get_height() - 32, 'Página {PAGE_NUM} de {PAGE_COUNT}',
            $dompdf->getFontMetrics()->getFont('DejaVu Sans'), 7.5, [0.42, 0.4, 0.36]);

        return $pdf;
    }
}
