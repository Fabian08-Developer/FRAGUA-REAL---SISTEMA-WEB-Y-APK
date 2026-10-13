<?php

namespace App\Services;

/**
 * M08 — RF-M08-011/012: genera el archivo exportado a partir de la tabla ya calculada por
 * ReporteService::aTabla(). La auditoría de la exportación (RF-M08-014) vive en
 * ReporteService::registrarExportacion(), no aquí — este Service solo arma bytes.
 *
 * Qué es real y qué no (ver README-BACKEND.md, "Qué falta a propósito"):
 * - Excel se genera como CSV real (fputcsv) — Excel lo abre nativamente. Falta agregar una
 *   librería de hojas de cálculo (ej. phpoffice/phpspreadsheet) para un .xlsx con formato.
 * - El PDF es un PDF real de una sola página (formato binario válido, sin dependencias
 *   externas), no una plantilla. Un reporte más largo que una página se trunca — falta
 *   paginación real y una librería como dompdf para reportes extensos.
 */
class ReporteExportService
{
    private const MAX_LINEAS_PDF = 45;

    public function generarExcel(array $encabezados, array $filas): string
    {
        $buffer = fopen('php://temp', 'r+');
        fputcsv($buffer, $encabezados);
        foreach ($filas as $fila) {
            fputcsv($buffer, $fila);
        }
        rewind($buffer);
        $csv = stream_get_contents($buffer);
        fclose($buffer);

        return $csv;
    }

    public function generarPdf(string $titulo, array $lineas): string
    {
        $truncado = count($lineas) > self::MAX_LINEAS_PDF;
        $lineas = array_slice($lineas, 0, self::MAX_LINEAS_PDF);
        if ($truncado) {
            $lineas[] = '... (reporte truncado; use el Excel para el detalle completo)';
        }

        $contenido = "BT /F1 10 Tf 40 760 Td ({$this->escapar($titulo)}) Tj 0 -20 Td\n";
        foreach ($lineas as $linea) {
            $contenido .= "({$this->escapar($linea)}) Tj 0 -14 Td\n";
        }
        $contenido .= 'ET';

        $objetos = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /Resources << /Font << /F1 4 0 R >> >> /MediaBox [0 0 612 792] /Contents 5 0 R >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>',
            5 => "<< /Length " . strlen($contenido) . " >>\nstream\n{$contenido}\nendstream",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objetos as $numero => $cuerpo) {
            $offsets[$numero] = strlen($pdf);
            $pdf .= "{$numero} 0 obj\n{$cuerpo}\nendobj\n";
        }

        $xrefInicio = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objetos) + 1) . "\n0000000000 65535 f \n";
        foreach ($objetos as $numero => $cuerpo) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$numero]);
        }
        $pdf .= "trailer\n<< /Size " . (count($objetos) + 1) . " /Root 1 0 R >>\nstartxref\n{$xrefInicio}\n%%EOF";

        return $pdf;
    }

    private function escapar(string $texto): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $texto);
    }
}
