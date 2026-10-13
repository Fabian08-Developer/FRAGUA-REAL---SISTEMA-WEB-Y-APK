<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Mesa;
use App\Models\Pedido;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * Dashboard del Administrador (Cronograma_Fragua_Real.xlsx, Semana 2 Lunes — "Resumen
 * general, no es un módulo propio"). Solo lectura: agrega directamente sobre lo ya
 * registrado en M02/M05/M07 (mismo principio que M08 — RN-M08-01/02 — pero sin periodo
 * elegible por el Administrador: el Dashboard siempre muestra "hoy").
 */
class DashboardController extends Controller
{
    public function resumen(): JsonResponse
    {
        $hoy = CarbonImmutable::now()->startOfDay();

        // RF-M08-002 aplicado a "hoy": mismo criterio que ReporteService::ventasTotales
        // (estado finalizado + finalizado_at dentro del rango) para que este número
        // coincida siempre con el reporte de Ventas Totales del día (RNF-M08-002).
        $ventasHoy = Pedido::where('estado', 'finalizado')
            ->whereBetween('finalizado_at', [$hoy, $hoy->endOfDay()])
            ->selectRaw('COUNT(*) as cantidad_pedidos, COALESCE(SUM(total), 0) as monto_total')
            ->first();

        // "Activo" = no finalizado ni cancelado (mismo criterio que Mesa::tienePedidosActivos()
        // y CajaView.vue del Mesero), desglosado por canal para que el Administrador vea de
        // un vistazo dónde está la carga (local/domicilio/recogida).
        $pedidosActivos = Pedido::whereNotIn('estado', ['finalizado', 'cancelado'])
            ->selectRaw('tipo, COUNT(*) as cantidad')
            ->groupBy('tipo')
            ->pluck('cantidad', 'tipo');

        // Solo mesas activas (una mesa dada de baja no cuenta ni en el total ni en ocupadas).
        $mesas = Mesa::where('activa', true)
            ->selectRaw("COUNT(*) as total, SUM(CASE WHEN estado = 'ocupada' THEN 1 ELSE 0 END) as ocupadas")
            ->first();

        return response()->json([
            'ventas_hoy' => [
                'monto_total' => (float) $ventasHoy->monto_total,
                'cantidad_pedidos' => (int) $ventasHoy->cantidad_pedidos,
            ],
            'pedidos_activos' => [
                'total' => (int) $pedidosActivos->sum(),
                'local' => (int) ($pedidosActivos['local'] ?? 0),
                'domicilio' => (int) ($pedidosActivos['domicilio'] ?? 0),
                'recogida' => (int) ($pedidosActivos['recogida'] ?? 0),
            ],
            'mesas' => [
                'ocupadas' => (int) $mesas->ocupadas,
                'total' => (int) $mesas->total,
            ],
        ]);
    }
}
