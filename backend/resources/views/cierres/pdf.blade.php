{{-- Fase 5, tarea 2 (RF-M03-28/29, RNF-M03-09/10, HU-M03-11/12): cierre diario en PDF, tamaño
     carta. Lleva el día, los totales tal como se cerraron, el desglose, el arqueo de caja, las
     correcciones posteriores con los valores actualizados y los responsables. --}}
@php
    $pesos = fn ($v) => ((float) $v < 0 ? '−' : '').'$'.number_format(abs((float) $v), 0, ',', '.');
    $fechaHora = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->setTimezone('America/Bogota')->format('d/m/Y g:i a') : '';
    $dia = \Illuminate\Support\Carbon::parse($cierre->fecha->toDateString(), 'America/Bogota')->locale('es')->isoFormat('dddd D [de] MMMM [de] YYYY');
    $metodos = ['efectivo' => 'Efectivo', 'tarjeta' => 'Tarjeta', 'transferencia' => 'Transferencia', 'en_linea' => 'En línea', 'deposito_reserva' => 'Depósito de reserva', 'otro' => 'Otro'];
    $tipos = ['alimento' => 'Alimentos', 'bebida' => 'Bebidas', 'otro' => 'Otros'];
    $acciones = ['registrar' => 'Registró', 'editar' => 'Corrigió', 'anular' => 'Anuló'];
    $reimpresion = $reimpresion ?? null;
    $d = $d ?? null;
@endphp
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Cierre diario {{ $cierre->fecha->toDateString() }}</title>
<style>
    @page { margin: 40px 44px 54px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #1f1b16; }
    h1 { font-size: 18px; margin: 0; }
    h2 { font-size: 11px; text-transform: uppercase; letter-spacing: 0.6px; color: #6c655c; margin: 16px 0 5px; }
    .quieto { color: #6c655c; }
    .derecha { text-align: right; }
    table { width: 100%; border-collapse: collapse; }
    td, th { vertical-align: top; }
    .encabezado td { padding: 0; }
    .totales td { width: 25%; border: 1px solid #e2dbcf; padding: 8px 10px; }
    .totales .etiqueta { font-size: 9px; color: #6c655c; }
    .totales .valor { font-size: 14px; font-weight: bold; margin-top: 2px; }
    .lista td { padding: 3px 0; border-bottom: 1px solid #f1ece4; }
    .lista th { text-align: left; font-size: 9px; color: #6c655c; font-weight: normal; border-bottom: 1px solid #e2dbcf; padding: 3px 0; }
    .lista th.derecha { text-align: right; }
    .columnas td.col { width: 50%; padding-right: 14px; }
    .columnas td.col + td.col { padding-right: 0; padding-left: 14px; }
    .ajustes { border: 1px solid #e0b25b; background: #fdf6e7; padding: 8px 10px; margin-top: 14px; }
    .reimpresion { display: inline-block; border: 1px solid #1f1b16; padding: 1px 6px; font-weight: bold; letter-spacing: 0.5px; margin-top: 4px; }
    .nota { font-style: italic; color: #6c655c; margin-top: 6px; }
    .firmas td { width: 50%; padding-top: 34px; }
    .firmas .linea { border-top: 1px solid #1f1b16; padding-top: 3px; margin-right: 24px; }
    .pie { position: fixed; bottom: -34px; left: 0; right: 0; font-size: 8.5px; color: #6c655c; }
    .positivo { color: #1d6b3a; } .negativo { color: #a3261c; }
</style>
</head>
<body>
<div class="pie">
    <table><tr>
        <td>{{ $negocio['nombre'] ?? 'Fragua Real Burger' }} · Cierre diario del {{ $cierre->fecha->format('d/m/Y') }} · impreso por {{ $impresoPor }} el {{ $fechaHora(now()) }}@if ($reimpresion) · REIMPRESIÓN copia {{ $reimpresion['vez'] }}@endif</td>
    </tr></table>
</div>

<table class="encabezado">
    <tr>
        <td>
            <h1>{{ $negocio['nombre'] ?? 'Fragua Real Burger' }}</h1>
            @if (! empty($negocio['nit']))<div>NIT {{ $negocio['nit'] }}</div>@endif
            <div class="quieto">{{ $negocio['direccion'] ?? '' }}</div>
        </td>
        <td class="derecha">
            <div style="font-size: 13px; font-weight: bold;">Cierre diario</div>
            <div style="font-size: 11px;">{{ \Illuminate\Support\Str::ucfirst($dia) }}</div>
            <div class="quieto">Cerrado por {{ $cierre->cerradoPorUsuario?->nombre ?? '—' }} el {{ $fechaHora($cierre->created_at) }}</div>
            @if ($reimpresion)
                <div class="reimpresion">REIMPRESIÓN · copia {{ $reimpresion['vez'] }}</div>
            @endif
        </td>
    </tr>
</table>

<h2>Totales del cierre</h2>
<table class="totales">
    <tr>
        <td><div class="etiqueta">Ventas @if ($d) ({{ $d['ventas']['pedidos'] ?? 0 }} pedidos) @endif</div><div class="valor">{{ $pesos($cierre->total_ventas) }}</div></td>
        <td><div class="etiqueta">Compras</div><div class="valor">{{ $pesos($cierre->total_compras) }}</div></td>
        <td><div class="etiqueta">Otros gastos</div><div class="valor">{{ $pesos($cierre->total_gastos) }}</div></td>
        <td><div class="etiqueta">Resultado operativo</div><div class="valor {{ (float) $cierre->resultado_operativo >= 0 ? 'positivo' : 'negativo' }}">{{ $pesos($cierre->resultado_operativo) }}</div></td>
    </tr>
</table>
<div class="nota">{{ $nota }}</div>

@if ($ajustes->isNotEmpty())
    <div class="ajustes">
        <strong>Correcciones después del cierre</strong>
        <table class="lista" style="margin-top: 4px;">
            <tr><th>Fecha</th><th>Quién autorizó</th><th>Corrección</th><th>Motivo</th><th class="derecha">Compras</th><th class="derecha">Gastos</th></tr>
            @foreach ($ajustes as $a)
                <tr>
                    <td>{{ $fechaHora($a->created_at) }}</td>
                    <td>{{ $a->autorizadoPorUsuario?->nombre ?? '—' }}</td>
                    <td>{{ $acciones[$a->accion] ?? $a->accion }} "{{ $a->compraGasto?->descripcion ?? 'registro #'.$a->compra_gasto_id }}"</td>
                    <td>{{ $a->motivo }}</td>
                    <td class="derecha">{{ (float) $a->delta_compras ? $pesos($a->delta_compras) : '' }}</td>
                    <td class="derecha">{{ (float) $a->delta_gastos ? $pesos($a->delta_gastos) : '' }}</td>
                </tr>
            @endforeach
        </table>
        <div style="margin-top: 6px;"><strong>Valores actualizados:</strong> compras {{ $pesos($actualizado['total_compras']) }} · otros gastos {{ $pesos($actualizado['total_gastos']) }} · resultado operativo {{ $pesos($actualizado['resultado_operativo']) }}</div>
    </div>
@endif

@if ($d)
    <table class="columnas" style="margin-top: 4px;">
        <tr>
            <td class="col">
                <h2>Ventas por tipo</h2>
                <table class="lista">
                    @forelse ($d['ventas']['por_tipo'] ?? [] as $t)
                        <tr><td>{{ $tipos[$t['tipo_venta']] ?? $t['tipo_venta'] }}</td><td class="derecha">{{ $pesos($t['monto']) }}</td></tr>
                    @empty
                        <tr><td class="quieto">Sin ventas.</td></tr>
                    @endforelse
                </table>
            </td>
            <td class="col">
                <h2>Ventas por forma de pago</h2>
                <table class="lista">
                    @forelse ($d['ventas']['por_metodo'] ?? [] as $m)
                        <tr><td>{{ $metodos[$m['metodo']] ?? $m['metodo'] }}</td><td class="derecha">{{ $pesos($m['monto']) }}</td></tr>
                    @empty
                        <tr><td class="quieto">Sin pagos.</td></tr>
                    @endforelse
                </table>
            </td>
        </tr>
        <tr>
            <td class="col">
                <h2>Compras por categoría</h2>
                <table class="lista">
                    @forelse ($d['compras']['por_categoria'] ?? [] as $c)
                        <tr><td>{{ $c['categoria'] }} ({{ $c['cantidad'] }})</td><td class="derecha">{{ $pesos($c['monto']) }}</td></tr>
                    @empty
                        <tr><td class="quieto">Sin compras.</td></tr>
                    @endforelse
                </table>
            </td>
            <td class="col">
                <h2>Otros gastos por categoría</h2>
                <table class="lista">
                    @forelse ($d['gastos']['por_categoria'] ?? [] as $c)
                        <tr><td>{{ $c['categoria'] }} ({{ $c['cantidad'] }})</td><td class="derecha">{{ $pesos($c['monto']) }}</td></tr>
                    @empty
                        <tr><td class="quieto">Sin gastos.</td></tr>
                    @endforelse
                </table>
            </td>
        </tr>
    </table>

    <h2>Compras y gastos por forma de pago</h2>
    <table class="lista">
        <tr><th>Forma de pago</th><th class="derecha">Compras</th><th class="derecha">Gastos</th><th class="derecha">Total</th></tr>
        @forelse ($d['compras_gastos_por_forma_de_pago'] ?? [] as $f)
            <tr><td>{{ $metodos[$f['metodo']] ?? $f['metodo'] }}</td><td class="derecha">{{ $pesos($f['compras']) }}</td><td class="derecha">{{ $pesos($f['gastos']) }}</td><td class="derecha">{{ $pesos($f['total']) }}</td></tr>
        @empty
            <tr><td colspan="4" class="quieto">Sin registros.</td></tr>
        @endforelse
    </table>

    <h2>Arqueo de caja</h2>
    @if (empty($d['caja']))
        <p class="quieto">No hubo turno de caja ese día.</p>
    @else
        <table class="lista">
            <tr><td>Base inicial</td><td class="derecha">{{ $pesos($d['caja']['base_inicial']) }}</td></tr>
            <tr><td>Efectivo esperado</td><td class="derecha">{{ $pesos($d['caja']['efectivo_esperado']) }}</td></tr>
            @if ($d['caja']['efectivo_contado'] !== null)
                <tr><td>Efectivo contado</td><td class="derecha">{{ $pesos($d['caja']['efectivo_contado']) }}</td></tr>
                <tr><td>Diferencia</td><td class="derecha"><strong>{{ $pesos($d['caja']['diferencia']) }}</strong></td></tr>
            @endif
            @if (! empty($d['caja']['justificacion']))
                <tr><td colspan="2">Justificación: "{{ $d['caja']['justificacion'] }}"</td></tr>
            @endif
        </table>
    @endif
@else
    <p class="quieto" style="margin-top: 12px;">Este cierre se hizo antes de que el sistema guardara el desglose (Fase 2): solo tiene los totales.</p>
@endif

<table class="firmas">
    <tr>
        <td><div class="linea">Cerró: {{ $cierre->cerradoPorUsuario?->nombre ?? '—' }}</div></td>
        <td><div class="linea">Revisó</div></td>
    </tr>
</table>
</body>
</html>
