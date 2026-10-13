<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ReporteExportRequest;
use App\Http\Requests\Api\ReporteFiltroRequest;
use App\Services\ReporteExportService;
use App\Services\ReporteService;
use App\Support\Reportes\PeriodoReporte;
use Closure;
use Illuminate\Http\JsonResponse;

/**
 * M08 — Reportes (ADR-006: sin tablas propias, solo lectura — RN-M08-01/02). Este
 * Controller resuelve el periodo (RF-M08-001, RN-M08-03), delega el cálculo de cada
 * reporte a ReporteService, arma la comparación contra el periodo anterior cuando se pide
 * (RF-M08-010) y la exportación a PDF/Excel con su auditoría (RF-M08-011/012/014).
 */
class ReporteController extends Controller
{
    public function __construct(
        private readonly ReporteService $reporteService,
        private readonly ReporteExportService $exportService,
    ) {
    }

    public function ventasTotales(ReporteFiltroRequest $request): JsonResponse
    {
        return $this->responder($request, fn ($d, $h) => $this->reporteService->ventasTotales($d, $h));
    }

    public function ventasPorProducto(ReporteFiltroRequest $request): JsonResponse
    {
        return $this->responder(
            $request,
            fn ($d, $h) => $this->reporteService->ventasPorProductoYCategoria($d, $h, $request->integer('limite', 10))
        );
    }

    public function ventasPorTipoYMetodo(ReporteFiltroRequest $request): JsonResponse
    {
        return $this->responder($request, fn ($d, $h) => $this->reporteService->ventasPorTipoYMetodo($d, $h));
    }

    public function comprasYGastos(ReporteFiltroRequest $request): JsonResponse
    {
        // CORREGIDO (01/10/2026): comprasYGastos() recibe fechas de calendario en hora de
        // Colombia (desdeFecha/hastaFecha), no los instantes UTC — ver PeriodoReporte.
        return $this->responder($request, fn ($d, $h, $df, $hf) => $this->reporteService->comprasYGastos($df, $hf));
    }

    public function resultadoOperativo(ReporteFiltroRequest $request): JsonResponse
    {
        return $this->responder($request, fn ($d, $h, $df, $hf) => $this->reporteService->resultadoOperativo($df, $hf));
    }

    public function usoDeMesas(ReporteFiltroRequest $request): JsonResponse
    {
        return $this->responder($request, fn ($d, $h) => $this->reporteService->usoDeMesas($d, $h));
    }

    // RF-M08-011/012: exporta el reporte pedido; RF-M08-014: deja auditoría de la exportación.
    public function exportar(ReporteExportRequest $request)
    {
        $periodo = PeriodoReporte::resolver($request->periodoElegido(), $request->desde, $request->hasta);
        $datos = $this->calcular($request->reporte, $periodo, $request->integer('limite', 10));
        $tabla = $this->reporteService->aTabla($request->reporte, $datos);

        $this->reporteService->registrarExportacion($request->reporte, $periodo->aArray(), $request->formato, $request->user());

        if ($request->formato === 'excel') {
            $contenido = $this->exportService->generarExcel($tabla['encabezados'], $tabla['filas']);

            return response($contenido, 200, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => "attachment; filename=\"{$request->reporte}.csv\"",
            ]);
        }

        $lineas = array_map(fn ($fila) => implode(' | ', $fila), $tabla['filas']);
        $contenido = $this->exportService->generarPdf($request->reporte, $lineas);

        return response($contenido, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$request->reporte}.pdf\"",
        ]);
    }

    private function calcular(string $reporte, PeriodoReporte $periodo, int $limite): array
    {
        return match ($reporte) {
            'ventas-totales' => $this->reporteService->ventasTotales($periodo->desde, $periodo->hasta),
            'ventas-por-producto' => $this->reporteService->ventasPorProductoYCategoria($periodo->desde, $periodo->hasta, $limite),
            'ventas-por-tipo-y-metodo' => $this->reporteService->ventasPorTipoYMetodo($periodo->desde, $periodo->hasta),
            'compras-y-gastos' => $this->reporteService->comprasYGastos($periodo->desdeFecha, $periodo->hastaFecha),
            'resultado-operativo' => $this->reporteService->resultadoOperativo($periodo->desdeFecha, $periodo->hastaFecha),
            'uso-de-mesas' => $this->reporteService->usoDeMesas($periodo->desde, $periodo->hasta),
        };
    }

    // RN-M08-03/04/05: resuelve el periodo, calcula el reporte y, si se pide (RF-M08-010),
    // lo compara contra el periodo equivalente inmediatamente anterior.
    private function responder(ReporteFiltroRequest $request, Closure $calcular): JsonResponse
    {
        $periodo = PeriodoReporte::resolver($request->periodoElegido(), $request->desde, $request->hasta);
        $reporte = $calcular($periodo->desde, $periodo->hasta, $periodo->desdeFecha, $periodo->hastaFecha);

        $respuesta = [
            'periodo' => $periodo->aArray(),
            'reporte' => $reporte,
        ];

        if ($request->boolean('comparar')) {
            $anterior = $periodo->anterior();
            $reporteAnterior = $calcular($anterior->desde, $anterior->hasta, $anterior->desdeFecha, $anterior->hastaFecha);
            $respuesta['comparacion'] = [
                'periodo_anterior' => $anterior->aArray(),
                'variacion' => $this->reporteService->compararResumen($reporte['resumen'], $reporteAnterior['resumen']),
            ];
        }

        return response()->json($respuesta);
    }
}
