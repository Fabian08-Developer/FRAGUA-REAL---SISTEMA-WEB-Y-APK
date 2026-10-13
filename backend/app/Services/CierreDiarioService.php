<?php

namespace App\Services;

use App\Models\CajaTurno;
use App\Models\CierreAjuste;
use App\Models\CierreDiario;
use App\Models\CompraGasto;
use App\Models\Pago;
use App\Models\Pedido;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * M03 — Cierre Diario. Igual que CompraGastoService, este Service no estaba en el
 * documento de arquitectura: las reglas de cálculo vivían implícitas y ningún
 * componente descrito en el documento era responsable de calcularlas.
 *
 * A diferencia de M08 (Reportes, ADR-006 — solo lectura), un cierre diario SÍ se persiste:
 * es la foto contable del día, no un reporte recalculable bajo demanda.
 *
 * Fase 2, tarea 5 (12/10/2026) — cierre completo:
 *  - calcular(): el cálculo del día con su desglose (RF-M03-21 a 24, RN-M06-12): ventas por tipo
 *    de venta y por forma de pago, compras y gastos por categoría y forma de pago, y el arqueo de la
 *    caja del día (RN-M06-11). Sirve para la consulta previa (RF-M03-26) y para el cierre formal.
 *  - generar(): el cierre formal guarda totales y desglose (RF-M03-25/27); exige que la caja del día
 *    ya esté cerrada, porque el arqueo alimenta el cierre (RN-M06-11), y no acepta fechas futuras.
 *  - Correcciones después del cierre (RF-M03-20, RN-M03-08/15, HU-M03-09, decisión D7): no reescriben
 *    el cierre; cada una queda en `cierre_ajustes` y la consulta muestra el cierre original, sus
 *    ajustes y los valores actualizados.
 *  - El resultado es operativo/estimado, no la utilidad contable (RF-M03-34, RN-M03-10).
 */
class CierreDiarioService
{
    private const ZONA_HORARIA_NEGOCIO = 'America/Bogota';

    public const NOTA_RESULTADO = 'Resultado operativo estimado (ventas − compras − otros gastos). No es la utilidad contable del negocio.';

    public function __construct(
        private readonly CompraGastoService $compraGastoService,
    ) {
    }

    public function yaExiste(\DateTimeInterface|string $fecha): bool
    {
        return $this->cierreDe($fecha) !== null;
    }

    public function cierreDe(\DateTimeInterface|string $fecha): ?CierreDiario
    {
        return CierreDiario::whereDate('fecha', $this->dia($fecha))->first();
    }

    /** Cálculo del día con su desglose, sin guardar nada (RF-M03-26). */
    public function calcular(\DateTimeInterface|string $fecha): array
    {
        $dia = $this->dia($fecha);
        $inicio = CarbonImmutable::parse($dia, self::ZONA_HORARIA_NEGOCIO)->startOfDay();
        $fin = $inicio->endOfDay();

        // Ventas: pedidos finalizados ese día (hora de Colombia), por su total (incluye domicilio).
        $pedidos = Pedido::where('estado', 'finalizado')->whereBetween('finalizado_at', [$inicio->utc(), $fin->utc()]);
        $totalVentas = (float) (clone $pedidos)->sum('total');
        $cantidadPedidos = (int) (clone $pedidos)->count();

        // RN-M06-12: por tipo de venta (Bebida/Alimento/Otro), con las líneas ya descontadas.
        $porTipo = collect(app(ReporteService::class)->ventasPorTipoYMetodo($inicio->utc(), $fin->utc())['detalle']['por_tipo'])
            ->map(fn ($t) => ['tipo_venta' => $t->tipo_venta ?? $t['tipo_venta'], 'monto' => (float) ($t->monto_vendido ?? $t['monto_vendido'])])
            ->values()->all();

        // Por forma de pago: los pagos de esos pedidos (el abono de un depósito de reserva, aparte).
        $metodo = "CASE WHEN tipo = 'deposito' THEN 'deposito_reserva' ELSE metodo END";
        $porMetodo = Pago::whereIn('pedido_id', (clone $pedidos)->select('id'))
            ->where('estado', 'aprobado')->where('anulado', false)
            ->selectRaw("{$metodo} as metodo, COALESCE(SUM(monto), 0) as monto")
            ->groupBy(DB::raw($metodo))->orderBy('metodo')->get()
            ->map(fn ($p) => ['metodo' => $p->metodo, 'monto' => (float) $p->monto])->values()->all();

        // RF-M03-22/23/24, HU-M03-10: compras y gastos activos por categoría y por forma de pago.
        $registros = CompraGasto::where('estado', 'activo')->whereDate('fecha', $dia);
        $porCategoria = fn (string $tipo) => (clone $registros)->where('tipo', $tipo)
            ->selectRaw("COALESCE(categoria, 'Sin categoría') as categoria, COALESCE(SUM(valor), 0) as monto, COUNT(*) as cantidad")
            ->groupBy('categoria')->orderBy('categoria')->get()
            ->map(fn ($c) => ['categoria' => $c->categoria, 'monto' => (float) $c->monto, 'cantidad' => (int) $c->cantidad])->values()->all();
        $pagosRegistros = (clone $registros)
            ->selectRaw('metodo_pago, tipo, COALESCE(SUM(valor), 0) as monto')
            ->groupBy('metodo_pago', 'tipo')->get();
        $porFormaDePago = $pagosRegistros->groupBy('metodo_pago')->map(fn ($filas, $m) => [
            'metodo' => $m,
            'compras' => (float) $filas->where('tipo', 'compra')->sum('monto'),
            'gastos' => (float) $filas->where('tipo', 'gasto')->sum('monto'),
            'total' => (float) $filas->sum('monto'),
        ])->sortKeys()->values()->all();

        $totalCompras = $this->compraGastoService->totalPorTipoYFecha('compra', $inicio, $inicio);
        $totalGastos = $this->compraGastoService->totalPorTipoYFecha('gasto', $inicio, $inicio);

        // RN-M06-11: el arqueo de la caja del día alimenta el cierre.
        $turno = CajaTurno::whereDate('fecha', $dia)->first();

        return [
            'fecha' => $dia,
            'ventas' => ['total' => $totalVentas, 'pedidos' => $cantidadPedidos, 'por_tipo' => $porTipo, 'por_metodo' => $porMetodo],
            'compras' => ['total' => $totalCompras, 'por_categoria' => $porCategoria('compra')],
            'gastos' => ['total' => $totalGastos, 'por_categoria' => $porCategoria('gasto')],
            'compras_gastos_por_forma_de_pago' => $porFormaDePago,
            'resultado_operativo' => round($totalVentas - $totalCompras - $totalGastos, 2),
            'caja' => $turno ? [
                'id' => $turno->id,
                'estado' => $turno->estado,
                'base_inicial' => (float) $turno->base_inicial,
                'efectivo_esperado' => (float) $turno->efectivo_esperado,
                'efectivo_contado' => $turno->efectivo_contado !== null ? (float) $turno->efectivo_contado : null,
                'diferencia' => $turno->diferencia !== null ? (float) $turno->diferencia : null,
                'justificacion' => $turno->justificacion_descuadre,
            ] : null,
            'nota' => self::NOTA_RESULTADO,
        ];
    }

    /** RF-M03-26: lo que daría el cierre de ese día, y si ya se cerró. */
    public function previa(\DateTimeInterface|string $fecha): array
    {
        $calculo = $this->calcular($fecha);
        $cierre = $this->cierreDe($fecha);
        $caja = $calculo['caja'];

        return $calculo + [
            'cerrado' => $cierre !== null,
            'cierre_id' => $cierre?->id,
            'puede_cerrar' => $cierre === null && $this->dia($fecha) <= $this->hoy() && ($caja === null || $caja['estado'] !== 'abierta'),
            'motivo_no_cierra' => match (true) {
                $cierre !== null => 'Este día ya tiene cierre.',
                $this->dia($fecha) > $this->hoy() => 'No se puede cerrar un día que todavía no ha llegado.',
                $caja !== null && $caja['estado'] === 'abierta' => 'Primero cierra la caja del día (el arqueo alimenta el cierre).',
                default => null,
            },
        ];
    }

    public function generar(\DateTimeInterface|string $fecha, User $actor): CierreDiario
    {
        $dia = $this->dia($fecha);
        if ($this->yaExiste($dia)) {
            throw new RuntimeException('Ya existe un cierre diario para esta fecha.');
        }
        if ($dia > $this->hoy()) {
            throw new RuntimeException('No se puede cerrar un día que todavía no ha llegado.');
        }
        // RN-M06-11: el arqueo de caja se hace primero y alimenta el cierre.
        $turno = CajaTurno::whereDate('fecha', $dia)->first();
        if ($turno && $turno->estaAbierta()) {
            throw new RuntimeException('Primero cierra la caja del día: el arqueo alimenta el cierre (RN-M06-11).');
        }

        $calculo = $this->calcular($dia);

        $cierre = new CierreDiario();
        $cierre->fecha = $dia;
        $cierre->total_ventas = $calculo['ventas']['total'];
        $cierre->total_compras = $calculo['compras']['total'];
        $cierre->total_gastos = $calculo['gastos']['total'];
        $cierre->resultado_operativo = $calculo['resultado_operativo'];
        $cierre->detalle = $calculo;
        $cierre->caja_turno_id = $turno?->id;
        $cierre->cerrado_por = $actor->id;
        $cierre->save();

        // RNF-M03-04: el cierre formal queda en la auditoría con su responsable.
        app(AuditoriaService::class)->registrar('cierre_diario', 'CierreDiario', $cierre->id, [
            'fecha' => $dia,
            'total_ventas' => $calculo['ventas']['total'],
            'total_compras' => $calculo['compras']['total'],
            'total_gastos' => $calculo['gastos']['total'],
            'resultado_operativo' => $calculo['resultado_operativo'],
        ], $actor);

        return $cierre->load('cerradoPorUsuario');
    }

    /**
     * RF-M03-27, RN-M03-15, HU-M03-09: el cierre tal como se hizo, sus ajustes posteriores y los
     * valores actualizados (recalculados hoy), sin reescribir el cierre.
     */
    public function consulta(CierreDiario $cierre): array
    {
        $cierre->loadMissing(['cerradoPorUsuario', 'ajustes.autorizadoPorUsuario', 'ajustes.compraGasto']);
        $deltaCompras = (float) $cierre->ajustes->sum('delta_compras');
        $deltaGastos = (float) $cierre->ajustes->sum('delta_gastos');

        return [
            'cierre' => $cierre,
            'ajustes' => $cierre->ajustes,
            'actualizado' => [
                'total_ventas' => (float) $cierre->total_ventas,
                'total_compras' => round((float) $cierre->total_compras + $deltaCompras, 2),
                'total_gastos' => round((float) $cierre->total_gastos + $deltaGastos, 2),
                'resultado_operativo' => round((float) $cierre->resultado_operativo - $deltaCompras - $deltaGastos, 2),
            ],
            'calculo_hoy' => $this->calcular($cierre->fecha),
            'nota' => self::NOTA_RESULTADO,
        ];
    }

    /** Registra una corrección autorizada sobre un día cerrado (la llama CompraGastoService). */
    public function registrarAjuste(CierreDiario $cierre, ?CompraGasto $registro, string $accion, float $deltaCompras, float $deltaGastos, array $detalle, string $motivo, User $actor): CierreAjuste
    {
        $ajuste = CierreAjuste::create([
            'cierre_diario_id' => $cierre->id,
            'compra_gasto_id' => $registro?->id,
            'accion' => $accion,
            'delta_compras' => $deltaCompras,
            'delta_gastos' => $deltaGastos,
            'detalle' => $detalle,
            'motivo' => $motivo,
            'autorizado_por' => $actor->id,
        ]);

        app(AuditoriaService::class)->registrar('ajuste_cierre_diario', 'CierreDiario', $cierre->id, [
            'fecha' => $cierre->fecha->toDateString(),
            'accion' => $accion,
            'compra_gasto_id' => $registro?->id,
            'delta_compras' => $deltaCompras,
            'delta_gastos' => $deltaGastos,
            'motivo' => $motivo,
        ], $actor);

        return $ajuste;
    }

    private function dia(\DateTimeInterface|string $fecha): string
    {
        return is_string($fecha) ? Carbon::parse($fecha)->toDateString() : Carbon::instance($fecha)->toDateString();
    }

    private function hoy(): string
    {
        return now(self::ZONA_HORARIA_NEGOCIO)->toDateString();
    }
}
