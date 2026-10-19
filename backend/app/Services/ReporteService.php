<?php

namespace App\Services;

use App\Models\Auditoria;
use App\Models\CierreDiario;
use App\Models\CompraGasto;
use App\Models\Mesa;
use App\Models\Pago;
use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Models\Reserva;
use App\Models\User;
use App\Support\Reportes\PeriodoReporte;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * M08 — Reportes. RN-M08-01/02: M08 es de solo lectura y no mantiene una copia
 * independiente de la verdad — cada método agrega directamente los datos ya registrados
 * en M02/M07 (pedidos), M03 (compras/gastos/cierres), M04 (catálogo) y M05 (reservas), tal
 * como exige RNF-M08-002 (los totales de M08 deben coincidir con la suma de los módulos
 * fuente). Los 6 reportes reales del documento (sección 2 y RF-M08-002 a 009) son:
 * ventas totales, ventas por producto/categoría, ventas por tipo y método de pago, compras
 * y gastos, resultado operativo, y uso de mesas.
 */
class ReporteService
{
    // RF-M08-002: monto total y cantidad de pedidos finalizados del periodo.
    public function ventasTotales(CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        $agregado = Pedido::where('estado', 'finalizado')
            ->whereBetween('finalizado_at', [$desde, $hasta])
            ->selectRaw('COUNT(*) as cantidad_pedidos, COALESCE(SUM(total), 0) as monto_total')
            ->first();

        return [
            'resumen' => [
                'monto_total' => (float) $agregado->monto_total,
                'cantidad_pedidos' => (int) $agregado->cantidad_pedidos,
            ],
        ];
    }

    /**
     * Líneas vendidas del periodo (pedidos FINALIZADOS) con su producto o combo.
     *
     * CORREGIDO (Fase 0, tarea 5, 09/10/2026): antes se unía con productos usando JOIN, así
     * que las líneas de combo (producto_id nulo) desaparecían, y el monto se calculaba sin
     * los extras. Ahora entran los combos (con su propio tipo de venta, RF-M04-043) y el
     * valor de los extras se suma a la línea que los lleva, bajo el tipo de venta de esa
     * línea (RN-M04-26). Así ventas por producto y por tipo cuadran con lo cobrado.
     */
    private function lineasVendidas(CarbonImmutable $desde, CarbonImmutable $hasta)
    {
        return PedidoDetalle::join('pedidos', 'pedidos.id', '=', 'pedido_detalles.pedido_id')
            ->leftJoin('productos', 'productos.id', '=', 'pedido_detalles.producto_id')
            ->leftJoin('combos', 'combos.id', '=', 'pedido_detalles.combo_id')
            ->where('pedidos.estado', 'finalizado')
            ->whereBetween('pedidos.finalizado_at', [$desde, $hasta]);
    }

    // Monto de una línea: cantidad × (precio congelado + extras congelados por unidad) menos la
    // parte del descuento que le tocó (Fase 2, tarea 1: promociones y descuento manual), igual que
    // PedidoService::recalcularTotales(). Así las ventas por producto y por tipo suman lo cobrado.
    private const MONTO_LINEA = '(pedido_detalles.cantidad * (pedido_detalles.precio_unitario_congelado + COALESCE((SELECT SUM(pde.precio_congelado) FROM pedido_detalle_extra pde WHERE pde.pedido_detalle_id = pedido_detalles.id), 0)) - pedido_detalles.descuento)';

    // RF-M08-003/004: ventas por producto y por categoría, con más y menos vendidos. Los
    // combos aparecen como un artículo propio y se agrupan en la categoría "Combos".
    public function ventasPorProductoYCategoria(CarbonImmutable $desde, CarbonImmutable $hasta, int $limite = 10): array
    {
        $porProducto = $this->lineasVendidas($desde, $hasta)
            ->select(
                DB::raw('COALESCE(productos.id, combos.id) as id'),
                DB::raw("CASE WHEN pedido_detalles.combo_id IS NOT NULL THEN 'combo' ELSE 'producto' END as tipo_item"),
                DB::raw('COALESCE(productos.nombre, combos.nombre) as nombre'),
                DB::raw('SUM(pedido_detalles.cantidad) as unidades_vendidas'),
                DB::raw('SUM('.self::MONTO_LINEA.') as monto_vendido'),
            )
            ->groupBy('productos.id', 'productos.nombre', 'combos.id', 'combos.nombre', 'pedido_detalles.combo_id')
            ->orderByDesc('unidades_vendidas')
            ->get()
            // Un producto y un combo pueden compartir id: la clave los distingue en pantalla.
            ->groupBy(fn ($fila) => $fila->tipo_item.'-'.$fila->id)
            ->map(function ($filas, $clave) {
                $primera = $filas->first();

                return [
                    'clave' => $clave,
                    'id' => (int) $primera->id,
                    'es_combo' => $primera->tipo_item === 'combo',
                    'nombre' => $primera->nombre,
                    'unidades_vendidas' => (int) $filas->sum('unidades_vendidas'),
                    'monto_vendido' => (float) $filas->sum('monto_vendido'),
                ];
            })
            ->sortByDesc('unidades_vendidas')
            ->values();

        $porCategoria = $this->lineasVendidas($desde, $hasta)
            ->leftJoin('categorias', 'categorias.id', '=', 'productos.categoria_id')
            ->select(
                'categorias.id',
                DB::raw("COALESCE(categorias.nombre, 'Combos') as nombre"),
                DB::raw('SUM(pedido_detalles.cantidad) as unidades_vendidas'),
                DB::raw('SUM('.self::MONTO_LINEA.') as monto_vendido'),
            )
            ->groupBy('categorias.id', 'categorias.nombre')
            ->orderByDesc('monto_vendido')
            ->get();

        return [
            'resumen' => [
                'unidades_totales' => (int) $porProducto->sum('unidades_vendidas'),
                'monto_total' => (float) $porProducto->sum('monto_vendido'),
            ],
            'detalle' => [
                'por_producto' => $porProducto,
                // RF-M08-004: más vendidos y menos vendidos dentro del mismo listado.
                'mas_vendidos' => $porProducto->take($limite)->values(),
                'menos_vendidos' => $porProducto->sortBy('unidades_vendidas')->take($limite)->values(),
                'por_categoria' => $porCategoria,
            ],
        ];
    }

    // RF-M08-005: por tipo de venta (Bebida/Alimento/Otro, M04; el combo usa el suyo,
    // RF-M04-043). RF-M08-006: por método de pago (M06) — solo pagos aprobados y no anulados.
    public function ventasPorTipoYMetodo(CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        $tipo = 'COALESCE(productos.tipo_venta, combos.tipo_venta)';
        $porTipo = $this->lineasVendidas($desde, $hasta)
            ->select(
                DB::raw("{$tipo} as tipo_venta"),
                DB::raw('SUM(pedido_detalles.cantidad) as unidades_vendidas'),
                DB::raw('SUM('.self::MONTO_LINEA.') as monto_vendido'),
            )
            ->groupBy(DB::raw($tipo))
            ->get();

        // Fase 2, tarea 2: el abono del depósito de una reserva sale aparte ("deposito_reserva"),
        // porque ese dinero se recibió otro día (o por la pasarela) y no es un cobro del periodo.
        $metodo = "CASE WHEN tipo = 'deposito' THEN 'deposito_reserva' ELSE metodo END";
        $porMetodo = Pago::where('estado', 'aprobado')
            ->where('anulado', false)
            ->whereBetween('created_at', [$desde, $hasta])
            ->select(DB::raw("{$metodo} as metodo"), DB::raw('COUNT(*) as cantidad'), DB::raw('SUM(monto) as monto_total'))
            ->groupBy(DB::raw($metodo))
            ->get();

        return [
            'resumen' => [
                'monto_total' => (float) $porTipo->sum('monto_vendido'),
            ],
            'detalle' => [
                'por_tipo' => $porTipo,
                'por_metodo' => $porMetodo,
            ],
        ];
    }

    // RF-M08-007: compras y gastos del periodo, tomando los registros activos de M03.
    // CORREGIDO (01/10/2026): recibe fechas de calendario en hora de Colombia
    // (PeriodoReporte::$desdeFecha/$hastaFecha), no instantes UTC — 'fecha' es una
    // columna DATE sin hora, así que compararla con el instante UTC del límite del
    // periodo corría la fecha un día en los extremos (ver comentario de PeriodoReporte).
    public function comprasYGastos(string $desdeFecha, string $hastaFecha): array
    {
        $detalle = CompraGasto::where('estado', 'activo')
            ->whereBetween('fecha', [$desdeFecha, $hastaFecha])
            ->select('tipo', 'categoria', DB::raw('COUNT(*) as cantidad'), DB::raw('SUM(valor) as total'))
            ->groupBy('tipo', 'categoria')
            ->get();

        return [
            'resumen' => [
                'total_compras' => (float) $detalle->where('tipo', 'compra')->sum('total'),
                'total_gastos' => (float) $detalle->where('tipo', 'gasto')->sum('total'),
            ],
            'detalle' => [
                'por_categoria' => $detalle,
            ],
        ];
    }

    /**
     * RF-M08-008, RN-M08-06: ventas − compras − gastos del periodo, misma fórmula que M03
     * aplica a nivel diario. Recomendación de arquitectura (sección 15 de M08): agregar
     * sobre los cierres diarios que M03 ya calculó en vez de recorrer cada pedido de nuevo
     * — salvo los días sin cierre todavía (normalmente solo el día en curso, RN-M08-05),
     * que se calculan en vivo.
     *
     * CORREGIDO (01/10/2026): recibe fechas de calendario en hora de Colombia (igual que
     * comprasYGastos() arriba), no instantes UTC. Además, 'finalizado_at' SÍ es un
     * timestamp (no una columna DATE) guardado en UTC — extraer su fecha con DATE() a
     * secas da la fecha de calendario en UTC, que puede ser un día distinto a la fecha de
     * Bogotá para pedidos finalizados después de las 7:00 p. m. (hora Bogotá = antes de
     * medianoche UTC del día siguiente). Se convierte a hora de Colombia antes de
     * extraer la fecha, para agrupar cada pedido en el día de negocio correcto.
     */
    public function resultadoOperativo(string $desdeFecha, string $hastaFecha): array
    {
        $cierres = CierreDiario::whereBetween('fecha', [$desdeFecha, $hastaFecha])->get();
        $diasConCierre = $cierres->pluck('fecha')->map(fn ($f) => $f->toDateString())->all();

        $diasDelPeriodo = [];
        for ($dia = CarbonImmutable::parse($desdeFecha); $dia->toDateString() <= $hastaFecha; $dia = $dia->addDay()) {
            $diasDelPeriodo[] = $dia->toDateString();
        }
        $diasSinCierre = array_values(array_diff($diasDelPeriodo, $diasConCierre));

        $totalVentas = (float) $cierres->sum('total_ventas');
        $totalCompras = (float) $cierres->sum('total_compras');
        $totalGastos = (float) $cierres->sum('total_gastos');

        if (! empty($diasSinCierre)) {
            $placeholders = implode(',', array_fill(0, count($diasSinCierre), '?'));
            // Fase 7, tarea 5 (RNF-M08-001): el rango de horas de los días sin cierre deja usar el
            // índice de pedidos finalizados por fecha; sin él se leían todos los pedidos de la historia.
            $zona = 'America/Bogota';
            $rango = [
                CarbonImmutable::parse(min($diasSinCierre), $zona)->startOfDay()->utc(),
                CarbonImmutable::parse(max($diasSinCierre), $zona)->endOfDay()->utc(),
            ];

            $totalVentas += (float) Pedido::where('estado', 'finalizado')
                ->whereBetween('finalizado_at', $rango)
                ->whereRaw("DATE((finalizado_at AT TIME ZONE 'UTC') AT TIME ZONE 'America/Bogota') IN ({$placeholders})", $diasSinCierre)
                ->sum('total');

            $totalCompras += (float) CompraGasto::where('estado', 'activo')
                ->where('tipo', 'compra')
                ->whereIn('fecha', $diasSinCierre)
                ->sum('valor');

            $totalGastos += (float) CompraGasto::where('estado', 'activo')
                ->where('tipo', 'gasto')
                ->whereIn('fecha', $diasSinCierre)
                ->sum('valor');
        }

        // Mejora (16/10/2026): los depósitos que el negocio retuvo por no-show (RN-M05-18) son un
        // ingreso del día en que se marcó el no-show, aparte de las ventas. Se calculan siempre con las
        // reservas (también en días con cierre), porque los cierres anteriores a esta mejora no los
        // traían; es el mismo valor que guarda el cierre.
        $zona = 'America/Bogota';
        $otrosIngresos = app(CierreDiarioService::class)->depositosRetenidos(
            CarbonImmutable::parse($desdeFecha, $zona)->startOfDay(),
            CarbonImmutable::parse($hastaFecha, $zona)->endOfDay(),
        )['total'];

        return [
            'resumen' => [
                'total_ventas' => $totalVentas,
                'otros_ingresos' => $otrosIngresos,
                'total_compras' => $totalCompras,
                'total_gastos' => $totalGastos,
                'resultado_operativo' => $totalVentas + $otrosIngresos - $totalCompras - $totalGastos,
            ],
        ];
    }

    /**
     * RF-M08-009, HU-M08-007: uso de mesas del periodo. Reservas creadas, canceladas y liberadas por
     * no-show (por la hora de la reserva, M05) y la ocupación de cada mesa.
     *
     * CORREGIDO (Fase 5, tarea 4, 13/10/2026): la ocupación solo contaba reservas completadas, así que
     * una mesa llena de clientes sin reserva aparecía "sin uso". Ahora también cuenta los pedidos
     * locales de la mesa (M02), agrupados en visitas: un pedido abre una visita nueva cuando todos
     * los pedidos anteriores de esa mesa ya se habían finalizado o cancelado (la mesa se había
     * liberado); los que llegan mientras la mesa sigue ocupada son de la misma visita. Por mesa:
     * visitas, pedidos, ventas (pedidos finalizados), minutos promedio por visita (del primer pedido
     * al cierre del último, solo visitas ya cerradas) y reservas completadas. Se listan todas las
     * mesas activas, también las que no tuvieron uso.
     */
    public function usoDeMesas(CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        $porEstado = Reserva::whereBetween('fecha_hora', [$desde, $hasta])
            ->select('estado', DB::raw('COUNT(*) as cantidad'))
            ->groupBy('estado')
            ->get();

        $reservasCompletadas = Reserva::whereBetween('fecha_hora', [$desde, $hasta])
            ->where('estado', 'completada')
            ->select('mesa_id', DB::raw('COUNT(*) as cantidad'))
            ->groupBy('mesa_id')
            ->pluck('cantidad', 'mesa_id');

        // Fase 7, tarea 5 (RNF-M08-001): se leen filas simples, sin armar un modelo por pedido, y las
        // horas se comparan como segundos (se guardan en UTC). Con un mes de pedidos, crear los modelos
        // y sus fechas era casi todo el tiempo de este reporte. El resultado es el mismo.
        $pedidos = Pedido::where('tipo', 'local')->whereNotNull('mesa_id')
            ->where('estado', '!=', 'cancelado')
            ->whereBetween('created_at', [$desde, $hasta])
            ->orderBy('mesa_id')->orderBy('created_at')->orderBy('id')
            ->toBase()
            ->get(['id', 'mesa_id', 'estado', 'total', 'created_at', 'finalizado_at', 'cancelado_at']);
        $segundos = fn (?string $fecha) => $fecha === null ? null : strtotime($fecha.' UTC');

        $usoPorMesa = $pedidos->groupBy('mesa_id')->map(function ($deLaMesa) use ($segundos) {
            $visitas = [];
            foreach ($deLaMesa as $pedido) {
                $inicio = $segundos($pedido->created_at);
                $cierre = $segundos($pedido->finalizado_at ?? $pedido->cancelado_at); // null: sigue activo
                $actual = array_key_last($visitas);
                $sigueAbierta = $actual !== null
                    && ($visitas[$actual]['fin'] === null || $inicio <= $visitas[$actual]['fin']);
                if ($sigueAbierta) {
                    $fin = $visitas[$actual]['fin'];
                    $visitas[$actual]['fin'] = ($fin === null || $cierre === null) ? null : max($fin, $cierre);
                } else {
                    $visitas[] = ['inicio' => $inicio, 'fin' => $cierre];
                }
            }
            $cerradas = array_filter($visitas, fn ($v) => $v['fin'] !== null);

            return [
                'visitas' => count($visitas),
                'pedidos_locales' => $deLaMesa->count(),
                'ventas' => (float) $deLaMesa->where('estado', 'finalizado')->sum('total'),
                'minutos_promedio' => $cerradas
                    ? (int) round(array_sum(array_map(fn ($v) => ($v['fin'] - $v['inicio']) / 60, $cerradas)) / count($cerradas))
                    : null,
            ];
        });

        $mesasUsadas = $usoPorMesa->keys()->merge($reservasCompletadas->keys())->unique();
        $ocupacionPorMesa = Mesa::where('activa', true)->orWhereIn('id', $mesasUsadas)->orderBy('nombre')->get(['id', 'nombre'])
            ->map(fn (Mesa $mesa) => [
                'id' => $mesa->id,
                'nombre' => $mesa->nombre,
                'visitas' => $usoPorMesa[$mesa->id]['visitas'] ?? 0,
                'pedidos_locales' => $usoPorMesa[$mesa->id]['pedidos_locales'] ?? 0,
                'ventas' => $usoPorMesa[$mesa->id]['ventas'] ?? 0.0,
                'minutos_promedio' => $usoPorMesa[$mesa->id]['minutos_promedio'] ?? null,
                'reservas_completadas' => (int) ($reservasCompletadas[$mesa->id] ?? 0),
            ])
            ->sortByDesc(fn ($m) => [$m['visitas'], $m['reservas_completadas'], $m['ventas']])
            ->values();

        $cuenta = fn (string $estado) => (int) ($porEstado->firstWhere('estado', $estado)->cantidad ?? 0);

        return [
            'resumen' => [
                'reservas_creadas' => (int) $porEstado->sum('cantidad'),
                'reservas_canceladas' => $cuenta('cancelada'),
                'reservas_no_show' => $cuenta('no_show'),
                'visitas' => (int) $ocupacionPorMesa->sum('visitas'),
                'pedidos_locales' => (int) $ocupacionPorMesa->sum('pedidos_locales'),
                'mesas_con_uso' => $ocupacionPorMesa->filter(fn ($m) => $m['visitas'] > 0 || $m['reservas_completadas'] > 0)->count(),
            ],
            'detalle' => [
                'por_estado' => $porEstado,
                'ocupacion_por_mesa' => $ocupacionPorMesa,
            ],
        ];
    }

    // RF-M08-010, RN-M08-04: variación absoluta y porcentual de cada métrica del resumen
    // contra el periodo equivalente inmediatamente anterior.
    public function compararResumen(array $actual, array $anterior): array
    {
        $comparacion = [];
        foreach ($actual as $clave => $valorActual) {
            $valorAnterior = $anterior[$clave] ?? 0;
            $absoluta = $valorActual - $valorAnterior;
            $comparacion[$clave] = [
                'actual' => $valorActual,
                'anterior' => $valorAnterior,
                'variacion_absoluta' => $absoluta,
                'variacion_porcentual' => $valorAnterior != 0 ? round(($absoluta / $valorAnterior) * 100, 2) : null,
            ];
        }

        return $comparacion;
    }

    /**
     * Calcula uno de los 6 reportes para un periodo (RF-M08-002 a 009). Lo usan la pantalla y la
     * exportación (Fase 5, tarea 3), para que el archivo diga exactamente lo mismo que la pantalla
     * (CA-M08-009/010).
     */
    public function calcular(string $reporte, PeriodoReporte $periodo, int $limite = 10): array
    {
        return match ($reporte) {
            'ventas-totales' => $this->ventasTotales($periodo->desde, $periodo->hasta),
            'ventas-por-producto' => $this->ventasPorProductoYCategoria($periodo->desde, $periodo->hasta, $limite),
            'ventas-por-tipo-y-metodo' => $this->ventasPorTipoYMetodo($periodo->desde, $periodo->hasta),
            'compras-y-gastos' => $this->comprasYGastos($periodo->desdeFecha, $periodo->hastaFecha),
            'resultado-operativo' => $this->resultadoOperativo($periodo->desdeFecha, $periodo->hastaFecha),
            'uso-de-mesas' => $this->usoDeMesas($periodo->desde, $periodo->hasta),
            default => throw new InvalidArgumentException("Reporte desconocido: {$reporte}."),
        };
    }

    // RF-M08-014: deja auditoría de cada exportación (usuario, reporte, periodo, formato y, desde la
    // Fase 5, el archivo guardado y si comparaba con el periodo anterior).
    public function registrarExportacion(string $reporte, array $periodo, string $formato, User $actor, array $extra = []): void
    {
        app(AuditoriaService::class)->registrar('exportar_reporte', 'Reporte', $extra['exportacion_id'] ?? null, ['reporte' => $reporte, 'formato' => $formato, 'periodo' => $periodo] + $extra, $actor);
    }
}
