{{-- Fase 6, tarea 2 (RN-PED-06/09, D5): comanda de cocina, ticket de 80 mm que el Mesero imprime
     desde el navegador. $c es la copia guardada en comandas.contenido; no lleva precios. --}}
@php
    $hora = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->setTimezone('America/Bogota')->format('d/m/Y g:i a') : '';
    $titulos = ['inicial' => 'COMANDA', 'actualizacion' => 'CAMBIOS AL PEDIDO', 'cancelacion' => 'PEDIDO CANCELADO'];
    $acciones = ['agregar' => 'AGREGAR', 'quitar' => 'QUITAR', 'cambiar' => 'CAMBIAR'];
    $reimpresion = $reimpresion ?? null;
@endphp
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Comanda {{ $c['pedido'] }}-{{ $comanda->numero }}</title>
<style>
    @page { margin: 10px 9px; }
    body { font-family: 'DejaVu Sans Mono', monospace; font-size: 10px; color: #000; line-height: 1.35; }
    .centro { text-align: center; }
    .grande { font-size: 13px; font-weight: bold; }
    .linea { font-size: 11.5px; font-weight: bold; margin-top: 3px; }
    .detalle { padding-left: 14px; }
    hr { border: 0; border-top: 1px dashed #000; margin: 5px 0; }
    .preparado { text-decoration: line-through; }
</style>
</head>
<body>
<div class="centro grande">{{ $titulos[$comanda->tipo] ?? 'COMANDA' }}</div>
@if ($comanda->tipo === 'actualizacion')
    <div class="centro">Hoja {{ $comanda->numero }} del pedido: solo lo que cambió</div>
@endif
@if ($reimpresion)
    <div class="centro"><strong>*** REIMPRESIÓN · COPIA {{ $reimpresion['vez'] }} ***</strong></div>
    <div class="centro">Impresa el {{ $hora($reimpresion['fecha']) }}</div>
@endif
<hr>
<div class="grande">Pedido #{{ $c['pedido'] }} · {{ $c['canal'] }}</div>
@if (! empty($c['mesa']))<div class="grande">{{ $c['mesa'] }}</div>@endif
@if (! empty($c['cliente']))<div>Cliente: {{ $c['cliente'] }}</div>@endif
<div>{{ $hora($c['generada_at'] ?? $comanda->created_at) }}</div>
<hr>
@foreach ($c['lineas'] ?? [] as $l)
    @php
        $texto = (! empty($l['accion']) ? '['.($acciones[$l['accion']] ?? strtoupper($l['accion'])).'] ' : '').$l['cantidad'].' × '.$l['nombre'];
        if (($l['accion'] ?? null) === 'cambiar' && ! empty($l['cantidad_antes']) && $l['cantidad_antes'] !== $l['cantidad']) {
            $texto .= ' (antes '.$l['cantidad_antes'].')';
        }
    @endphp
    <div class="linea{{ ($comanda->tipo === 'inicial' && ! empty($l['preparado'])) ? ' preparado' : '' }}">{{ $texto }}</div>
    @foreach ($l['componentes'] ?? [] as $comp)<div class="detalle">- {{ $comp }}</div>@endforeach
    @if (! empty($l['extras']))<div class="detalle">+ {{ implode(', ', $l['extras']) }}</div>@endif
    @if (! empty($l['observaciones']))<div class="detalle">Nota: {{ $l['observaciones'] }}</div>@endif
@endforeach
@if (! empty($c['motivo']))
    <hr>
    <div>Motivo: {{ $c['motivo'] }}</div>
@endif
<hr>
<div class="centro">Comanda {{ $c['pedido'] }}-{{ $comanda->numero }}</div>
</body>
</html>
