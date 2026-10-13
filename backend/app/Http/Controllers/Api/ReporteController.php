<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ReporteExportRequest;
use App\Http\Requests\Api\ReporteFiltroRequest;
use App\Models\ReporteExportacion;
use App\Services\ReporteExportService;
use App\Services\ReporteService;
use App\Support\Reportes\PeriodoReporte;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * M08 — Reportes (ADR-006: sin tablas propias, solo lectura — RN-M08-01/02). Este
 * Controller resuelve el periodo (RF-M08-001, RN-M08-03), delega el cálculo de cada
 * reporte a ReporteService, arma la comparación contra el periodo anterior cuando se pide
 * (RF-M08-010) y la exportación a PDF/Excel con su auditoría (RF-M08-011/012/014).
 * Fase 5, tarea 3: la única tabla de M08 es `reporte_exportaciones`, el archivo de los documentos
 * ya exportados (RNF-M08-006); no guarda una copia de los datos de otros módulos.
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

    /**
     * RF-M08-011/012/014, RNF-M08-006 (Fase 5, tarea 3): genera el PDF o el Excel (.xlsx) del reporte
     * que está en pantalla, lo guarda, lo audita y lo entrega. Queda en "Exportaciones guardadas"
     * para volver a descargarlo tal cual (X-Exportacion-Id dice cuál es).
     */
    public function exportar(ReporteExportRequest $request)
    {
        $periodo = PeriodoReporte::resolver($request->periodoElegido(), $request->desde, $request->hasta);
        $exportacion = $this->exportService->exportar(
            $request->reporte, $request->formato, $periodo, $request->boolean('comparar'), $request->integer('limite', 10), $request->user(),
        );

        return $this->entregar($exportacion, $request, false);
    }

    // RNF-M08-006: las exportaciones guardadas, de la más reciente a la más antigua.
    public function exportaciones(Request $request): JsonResponse
    {
        abort_if(! $request->user()->esAdministrador(), 403);

        return response()->json(
            ReporteExportacion::with('generadoPorUsuario:id,nombre')->latest('id')->paginate(min(max($request->integer('per_page', 20), 1), 50))
        );
    }

    // RNF-M08-006: descarga el archivo tal como se generó, sin recalcular nada.
    public function descargar(Request $request, ReporteExportacion $exportacion)
    {
        abort_if(! $request->user()->esAdministrador(), 403);

        return $this->entregar($exportacion, $request, true);
    }

    // $descarga: false al exportar (se entrega el archivo recién generado); true al volver a
    // descargar uno guardado, que suma y queda en la auditoría.
    private function entregar(ReporteExportacion $exportacion, Request $request, bool $descarga)
    {
        try {
            $contenido = $this->exportService->contenidoGuardado($exportacion, $descarga ? $request->user() : null);
        } catch (RuntimeException $e) {
            abort(410, $e->getMessage());
        }

        return response($contenido, 200, [
            'Content-Type' => ReporteExportService::tipoContenido($exportacion->formato),
            'Content-Disposition' => "attachment; filename=\"{$exportacion->nombre_archivo}\"",
            'X-Exportacion-Id' => (string) $exportacion->id,
            'Access-Control-Expose-Headers' => 'Content-Disposition, X-Exportacion-Id',
        ]);
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
