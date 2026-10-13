<?php

namespace App\Services;

use App\Models\CierreDiario;
use App\Models\Pedido;
use App\Models\User;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * M03 — Cierre Diario. Igual que CompraGastoService, este Service no estaba en el
 * Documento Técnico de Arquitectura pero hacía falta: el modelo CierreDiario ya traía las
 * columnas (total_ventas, total_compras, total_gastos, resultado_operativo) pero ningún
 * componente descrito en el documento era responsable de calcularlas.
 *
 * A diferencia de M08 (Reportes, ADR-006 — solo lectura), un cierre diario SÍ se persiste:
 * es la foto contable del día, no un reporte recalculable bajo demanda.
 */
class CierreDiarioService
{
    private const ZONA_HORARIA_NEGOCIO = 'America/Bogota';

    public function __construct(
        private readonly CompraGastoService $compraGastoService,
    ) {
    }

    public function yaExiste(\DateTimeInterface $fecha): bool
    {
        return CierreDiario::whereDate('fecha', $fecha)->exists();
    }

    public function generar(\DateTimeInterface $fecha, User $actor): CierreDiario
    {
        if ($this->yaExiste($fecha)) {
            throw new RuntimeException('Ya existe un cierre diario para esta fecha.');
        }

        // Ventas: pedidos finalizados ese día, por su total (ya incluye valor_domicilio).
        // CORREGIDO (Fase 0, tarea 5, 09/10/2026): RF-M03-33. El día se toma en hora de
        // Bogotá, igual que los reportes (PeriodoReporte) y la caja. Antes se comparaba la
        // fecha en UTC, así que lo vendido después de las 7 p. m. caía en el día siguiente
        // y el cierre no coincidía con los reportes del mismo día.
        $inicio = Carbon::parse($fecha->format('Y-m-d'), self::ZONA_HORARIA_NEGOCIO)->startOfDay();
        $totalVentas = (float) Pedido::where('estado', 'finalizado')
            ->whereBetween('finalizado_at', [$inicio->copy()->utc(), $inicio->copy()->endOfDay()->utc()])
            ->sum('total');

        $totalCompras = $this->compraGastoService->totalPorTipoYFecha('compra', $fecha, $fecha);
        $totalGastos = $this->compraGastoService->totalPorTipoYFecha('gasto', $fecha, $fecha);

        $cierre = new CierreDiario();
        $cierre->fecha = $fecha;
        $cierre->total_ventas = $totalVentas;
        $cierre->total_compras = $totalCompras;
        $cierre->total_gastos = $totalGastos;
        $cierre->resultado_operativo = $totalVentas - $totalCompras - $totalGastos;
        $cierre->cerrado_por = $actor->id;
        $cierre->save();

        return $cierre;
    }
}
