{{-- Fase 5, tarea 3 (RF-M08-011, HU-M08-009, CA-M08-009): reporte exportado a PDF, tamaño carta,
     de las páginas que haga falta. Lleva el periodo, la marca de parcial (RN-M08-05), el resumen,
     la comparación con el periodo anterior si se pidió (RF-M08-010) y las tablas del detalle. --}}
@php
    $pesos = fn ($v) => ((float) $v < 0 ? '−' : '').'$'.number_format(abs((float) $v), 0, ',', '.');
    $numero = fn ($v) => number_format((float) $v, 0, ',', '.');
    $valor = fn ($v, $tipo) => $tipo === 'moneda' ? $pesos($v) : ($tipo === 'numero' ? $numero($v) : $v);
@endphp
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>{{ $doc['titulo'] }}</title>
<style>
    @page { margin: 40px 44px 56px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5px; color: #1f1b16; }
    h1 { font-size: 17px; margin: 0; }
    h2 { font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.6px; color: #6c655c; margin: 16px 0 5px; }
    .quieto { color: #6c655c; }
    .derecha { text-align: right; }
    table { width: 100%; border-collapse: collapse; }
    td, th { vertical-align: top; }
    .parcial { display: inline-block; margin-top: 4px; border: 1px solid #a3261c; color: #a3261c; padding: 1px 6px; font-weight: bold; }
    .resumen td { border: 1px solid #e2dbcf; padding: 7px 9px; }
    .resumen .etiqueta { font-size: 8.5px; color: #6c655c; }
    .resumen .cifra { font-size: 13px; font-weight: bold; margin-top: 2px; }
    .tabla thead { display: table-header-group; }
    .tabla th { text-align: left; font-size: 8.5px; color: #6c655c; font-weight: normal; border-bottom: 1px solid #c9bfae; padding: 4px 4px; background: #f7f3ec; }
    .tabla th.derecha { text-align: right; }
    .tabla td { padding: 3.5px 4px; border-bottom: 1px solid #f1ece4; }
    .tabla tr { page-break-inside: avoid; }
    .sube { color: #1d6b3a; } .baja { color: #a3261c; }
    .nota { font-style: italic; color: #6c655c; margin-top: 8px; }
    .pie { position: fixed; bottom: -36px; left: 0; right: 0; font-size: 8px; color: #6c655c; }
</style>
</head>
<body>
<div class="pie">
    {{ $negocio['nombre'] ?? 'Fragua Real Burger' }} · {{ $doc['titulo'] }} · {{ $doc['periodo'] }} · generado el {{ $doc['generado'] }} por {{ $doc['generado_por'] }}
</div>

<table>
    <tr>
        <td>
            @if (! empty($negocio['logo']))<img src="{{ $negocio['logo'] }}" alt="" style="max-height: 46px; max-width: 140px; margin-bottom: 4px;">@endif
            <h1>{{ $negocio['nombre'] ?? 'Fragua Real Burger' }}</h1>
            <div class="quieto">Reporte para el Administrador</div>
        </td>
        <td class="derecha">
            <div style="font-size: 13px; font-weight: bold;">{{ $doc['titulo'] }}</div>
            <div style="font-size: 10.5px;">{{ $doc['periodo'] }}</div>
            <div class="quieto">Generado el {{ $doc['generado'] }} por {{ $doc['generado_por'] }}</div>
            @if ($doc['parcial'])
                <div class="parcial">PERIODO EN CURSO · CIFRAS PARCIALES</div>
            @endif
        </td>
    </tr>
</table>

<h2>Resumen</h2>
<table class="resumen">
    @foreach (array_chunk($doc['resumen'], 4) as $grupo)
        <tr>
            @foreach ($grupo as [$texto, $cifra, $tipo])
                <td style="width: {{ 100 / count($grupo) }}%;"><div class="etiqueta">{{ $texto }}</div><div class="cifra">{{ $valor($cifra, $tipo) }}</div></td>
            @endforeach
        </tr>
    @endforeach
</table>
@if ($doc['nota'])<div class="nota">{{ $doc['nota'] }}</div>@endif

@if ($doc['comparacion'])
    <h2>Comparación con el periodo anterior</h2>
    <div class="quieto" style="margin-bottom: 4px;">{{ $doc['comparacion']['periodo'] }} (misma duración, justo antes)</div>
    <table class="tabla">
        <thead><tr><th>Métrica</th><th class="derecha">Este periodo</th><th class="derecha">Periodo anterior</th><th class="derecha">Variación</th><th class="derecha">Variación %</th></tr></thead>
        <tbody>
        @foreach ($doc['comparacion']['filas'] as [$texto, $actual, $anterior, $absoluta, $porcentaje, $tipo])
            <tr>
                <td>{{ $texto }}</td>
                <td class="derecha">{{ $valor($actual, $tipo) }}</td>
                <td class="derecha">{{ $valor($anterior, $tipo) }}</td>
                <td class="derecha {{ $absoluta > 0 ? 'sube' : ($absoluta < 0 ? 'baja' : '') }}">{{ $absoluta > 0 ? '+' : '' }}{{ $valor($absoluta, $tipo) }}</td>
                <td class="derecha">{{ $porcentaje === null ? 'sin dato anterior' : (($porcentaje > 0 ? '+' : '').number_format($porcentaje, 1, ',', '.').' %') }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif

@foreach ($doc['tablas'] as $tabla)
    <h2>{{ $tabla['titulo'] }}</h2>
    <table class="tabla">
        <thead><tr>
            @foreach ($tabla['columnas'] as $columna)
                <th class="{{ $columna['tipo'] !== 'texto' ? 'derecha' : '' }}">{{ $columna['texto'] }}</th>
            @endforeach
        </tr></thead>
        <tbody>
        @forelse ($tabla['filas'] as $fila)
            <tr>
                @foreach ($fila as $i => $celda)
                    <td class="{{ $tabla['columnas'][$i]['tipo'] !== 'texto' ? 'derecha' : '' }}">{{ $valor($celda, $tabla['columnas'][$i]['tipo']) }}</td>
                @endforeach
            </tr>
        @empty
            <tr><td colspan="{{ count($tabla['columnas']) }}" class="quieto">Sin datos en el periodo.</td></tr>
        @endforelse
        </tbody>
    </table>
@endforeach
</body>
</html>
