{{-- Fase 5, tarea 1 (RF-M06-010): comprobante digital (PDF, media carta) de domicilio y recogida. --}}
@php
    // Pesos y hora de Colombia; $c es la copia guardada en comprobantes.detalle.
    $pesos = fn ($v) => '$'.number_format((float) $v, 0, ',', '.');
    $fecha = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->setTimezone('America/Bogota')->format('d/m/Y g:i a') : '';
    $n = $c['negocio'] ?? [];
    $p = $c['pedido'] ?? [];
    $anulado = $comprobante->anulado_at !== null;
    // Reimpresión del personal (desde la segunda vez): ['vez' => n, 'fecha' => ...] o null.
    $reimpresion = $reimpresion ?? null;
@endphp
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Comprobante {{ $c['numero'] }}</title>
<style>
    @page { margin: 28px 32px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; color: #1f1b16; }
    h1 { font-size: 17px; margin: 0; }
    .quieto { color: #6c655c; }
    .derecha { text-align: right; }
    table { width: 100%; border-collapse: collapse; }
    .encabezado td { vertical-align: top; }
    .caja { border: 1px solid #e2dbcf; border-radius: 6px; padding: 8px 10px; margin-top: 12px; }
    .lineas th { text-align: left; font-size: 9.5px; color: #6c655c; border-bottom: 1px solid #e2dbcf; padding: 4px 0; }
    .lineas th.derecha { text-align: right; }
    .lineas td { padding: 5px 0; border-bottom: 1px solid #f1ece4; vertical-align: top; }
    .totales td { padding: 2px 0; }
    .total td { font-size: 13px; font-weight: bold; border-top: 1px solid #1f1b16; padding-top: 6px; }
    .sello { position: fixed; top: 38%; left: 0; right: 0; text-align: center; font-size: 64px; color: #c0392b; opacity: 0.18; transform: rotate(-24deg); font-weight: bold; }
    .reimpresion { margin-top: 4px; display: inline-block; border: 1px solid #1f1b16; padding: 1px 6px; font-weight: bold; font-size: 10px; letter-spacing: 0.5px; }
    .pie { margin-top: 18px; font-size: 9px; color: #6c655c; text-align: center; }
</style>
</head>
<body>
@if ($anulado)
    <div class="sello">ANULADO</div>
@endif
<table class="encabezado">
    <tr>
        <td>
            <h1>{{ $n['nombre'] ?? 'Fragua Real Burger' }}</h1>
            @if (! empty($n['nit']))<div>NIT {{ $n['nit'] }}</div>@endif
            <div class="quieto">{{ $n['direccion'] ?? '' }}</div>
            @if (! empty($n['telefono']))<div class="quieto">Tel. / WhatsApp {{ $n['telefono'] }}</div>@endif
        </td>
        <td class="derecha">
            <div style="font-size: 12px; font-weight: bold;">Comprobante de pago</div>
            <div style="font-size: 14px; font-weight: bold;">{{ $c['numero'] }}</div>
            <div class="quieto">{{ $fecha($c['fecha']) }}</div>
            @if ($reimpresion)
                <div class="reimpresion">REIMPRESIÓN · copia {{ $reimpresion['vez'] }}</div>
                <div class="quieto">Impresa el {{ $fecha($reimpresion['fecha']) }}</div>
            @endif
        </td>
    </tr>
</table>

<div class="caja">
    <table>
        <tr>
            <td><strong>Pedido #{{ $p['id'] }}</strong> · {{ $p['canal'] }}@if (! empty($p['mesa'])) · {{ $p['mesa'] }}@endif</td>
            <td class="derecha quieto">Pedido hecho el {{ $fecha($p['creado'] ?? null) }}</td>
        </tr>
        @if (! empty($p['cliente']))
            <tr><td colspan="2">Cliente: {{ $p['cliente'] }}@if (! empty($p['telefono'])) · {{ $p['telefono'] }}@endif</td></tr>
        @endif
        @if (! empty($p['direccion']))
            <tr><td colspan="2">Entrega: {{ $p['direccion'] }}</td></tr>
        @endif
    </table>
</div>

<table class="lineas" style="margin-top: 12px;">
    <thead><tr><th style="width: 12%;">Cant.</th><th>Producto</th><th class="derecha" style="width: 22%;">Valor</th></tr></thead>
    <tbody>
    @foreach ($c['lineas'] as $l)
        <tr>
            <td>{{ $l['cantidad'] }}</td>
            <td>{{ $l['nombre'] }}@if (! empty($l['extras']))<div class="quieto">+ {{ implode(', ', $l['extras']) }}</div>@endif</td>
            <td class="derecha">{{ $pesos($l['valor']) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table class="totales" style="margin-top: 8px; width: 55%; margin-left: 45%;">
    <tr><td>Subtotal</td><td class="derecha">{{ $pesos($c['subtotal']) }}</td></tr>
    @foreach ($c['descuentos'] as $d)
        <tr><td>{{ $d['texto'] }}</td><td class="derecha">−{{ $pesos($d['valor']) }}</td></tr>
    @endforeach
    @if ($c['valor_domicilio'] > 0)
        <tr><td>Domicilio</td><td class="derecha">{{ $pesos($c['valor_domicilio']) }}</td></tr>
    @endif
    <tr class="total"><td>Total</td><td class="derecha">{{ $pesos($c['total']) }}</td></tr>
</table>

<div class="caja">
    <strong>Pagos</strong>
    <table style="margin-top: 4px;">
        @foreach ($c['pagos'] as $pg)
            <tr><td>{{ $pg['texto'] }}</td><td class="quieto">{{ $fecha($pg['fecha']) }}</td><td class="derecha">{{ $pesos($pg['valor']) }}</td></tr>
        @endforeach
    </table>
</div>

@if ($anulado)
    <p style="color: #c0392b; margin-top: 12px;"><strong>Comprobante anulado</strong> el {{ $fecha($comprobante->anulado_at) }}: {{ $comprobante->anulado_motivo }}</p>
@endif

<div class="pie">
    Gracias por tu compra. Este comprobante de pago no es una factura electrónica.
</div>
</body>
</html>
