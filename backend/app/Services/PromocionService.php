<?php

namespace App\Services;

use App\Models\Categoria;
use App\Models\Producto;
use App\Models\Promocion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * M04 — Catálogo. RN-M04-19 a 22: promociones vigentes por fecha/franja/día de la semana,
 * con alcance a producto, a categoría completa o al total del pedido.
 *
 * Fase 2, tarea 1 (11/10/2026) — RF-M04-035 a 038, RN-M04-18 a 21:
 *  - La vigencia se evalúa en la hora de Colombia. Antes se usaba la hora del servidor (UTC), así
 *    que una promoción de almuerzo de 12:00 a 15:00 empezaba a las 7:00 a. m. del negocio.
 *  - calcular() reparte las rebajas sobre las líneas de un pedido. Lo usan PedidoService (al crear y
 *    al recalcular) y la cotización que ve el Cliente antes de confirmar, así nunca hay dos cuentas
 *    distintas del mismo pedido.
 *
 * Cómo se calcula (decisión documentada en README-BACKEND, punto 22):
 *  1. Promociones de PRODUCTO o CATEGORÍA: rebajan el precio del producto en cada línea que coincide
 *     (precio congelado × cantidad, sin los extras). Si es valor fijo, es por unidad. Varias que
 *     coinciden con la misma línea se suman (RN-M04-20), sin pasar del valor de la línea.
 *     Un combo tiene su propio precio: solo le aplican las promociones al total.
 *  2. Promociones al TOTAL: se calculan sobre lo que queda después del paso 1 (productos y extras;
 *     el valor del domicilio nunca se rebaja) y se reparten entre las líneas en proporción.
 *  3. Con un descuento manual se usa SOLO esa regla (RN-M04-21).
 *  Los montos se redondean al peso.
 */
class PromocionService
{
    public const ZONA_HORARIA_NEGOCIO = 'America/Bogota';

    public function vigente(Promocion $promocion, ?\DateTimeInterface $momento = null): bool
    {
        if (! $promocion->activo) {
            return false;
        }

        $momento = $this->enHoraDelNegocio($momento);

        if ($promocion->vigencia_inicio && $momento->format('Y-m-d') < $promocion->vigencia_inicio->format('Y-m-d')) {
            return false;
        }
        if ($promocion->vigencia_fin && $momento->format('Y-m-d') > $promocion->vigencia_fin->format('Y-m-d')) {
            return false;
        }

        if ($promocion->franja_inicio && $promocion->franja_fin) {
            $hora = $momento->format('H:i:s');
            if ($hora < $promocion->franja_inicio || $hora > $promocion->franja_fin) {
                return false;
            }
        }

        if (! empty($promocion->dias_semana)) {
            $diaIso = (int) $momento->format('N');
            if (! in_array($diaIso, array_map('intval', $promocion->dias_semana), true)) {
                return false;
            }
        }

        return true;
    }

    public static function enHoraDelNegocio(?\DateTimeInterface $momento = null): CarbonImmutable
    {
        return CarbonImmutable::instance($momento ?? now())->setTimezone(self::ZONA_HORARIA_NEGOCIO);
    }

    public function activasParaProducto(Producto $producto): Collection
    {
        return $producto->promociones()->where('activo', true)->where('tipo', 'automatica')->get()
            ->filter(fn (Promocion $p) => $this->vigente($p))
            ->values();
    }

    public function activasParaCategoria(Categoria $categoria): Collection
    {
        return $categoria->promociones()->where('activo', true)->where('tipo', 'automatica')->get()
            ->filter(fn (Promocion $p) => $this->vigente($p))
            ->values();
    }

    public function calcularDescuento(Promocion $promocion, float $base): float
    {
        if (! $this->vigente($promocion)) {
            return 0.0;
        }

        return $promocion->tipo_valor === 'porcentaje'
            ? round($base * ((float) $promocion->valor / 100), 2)
            : min($base, (float) $promocion->valor);
    }

    /**
     * Fotos (ver regla()) de las promociones AUTOMÁTICAS vigentes en ese momento (RF-M04-035).
     *
     * @return array<int, array<string, mixed>>
     */
    public function automaticasVigentes(?\DateTimeInterface $momento = null): array
    {
        return Promocion::with(['productos:id', 'categorias:id'])
            ->where('activo', true)->where('tipo', 'automatica')
            ->orderBy('id')->get()
            ->filter(fn (Promocion $p) => $this->vigente($p, $momento))
            ->map(fn (Promocion $p) => $this->regla($p))
            ->values()->all();
    }

    /** Foto de una promoción: lo necesario para calcular, aunque la promoción cambie después. */
    public function regla(Promocion $promocion): array
    {
        $promocion->loadMissing(['productos:id', 'categorias:id']);

        return [
            'promocion_id' => $promocion->id,
            'nombre' => $promocion->nombre,
            'alcance' => $promocion->alcance,
            // Mejora (16/10/2026): solo esa presentación del producto (null = todas).
            'variante_id' => $promocion->variante_id ? (int) $promocion->variante_id : null,
            'tipo_valor' => $promocion->tipo_valor,
            'valor' => (float) $promocion->valor,
            'productos' => $promocion->productos->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'categorias' => $promocion->categorias->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
        ];
    }

    /**
     * Reparte las rebajas de las reglas sobre las líneas.
     *
     * @param  array<int|string, array{producto_id: ?int, variante_id?: ?int, categoria_id: ?int, es_combo: bool, cantidad: int, precio_unitario: float, extras_unitario: float}>  $lineas
     * @param  array<int, array<string, mixed>>  $reglas
     * @return array{por_linea: array<int|string, float>, total: float, aplicadas: array<int, array{promocion_id: ?int, nombre: string, monto: float}>}
     */
    public function calcular(array $lineas, array $reglas): array
    {
        $porLinea = array_fill_keys(array_keys($lineas), 0.0);
        $aplicadas = [];

        // Paso 1: producto y categoría, sobre el precio del producto de cada línea.
        foreach ($reglas as $regla) {
            if (! in_array($regla['alcance'], ['producto', 'categoria'], true)) {
                continue;
            }
            $montoRegla = 0.0;
            foreach ($lineas as $clave => $linea) {
                if (! $this->coincide($regla, $linea)) {
                    continue;
                }
                $base = $linea['precio_unitario'] * $linea['cantidad'];
                $disponible = $base - $porLinea[$clave];
                $monto = $regla['tipo_valor'] === 'porcentaje'
                    ? round($base * $regla['valor'] / 100)
                    : round($regla['valor'] * $linea['cantidad']);
                $monto = max(0.0, min($disponible, $monto));
                $porLinea[$clave] += $monto;
                $montoRegla += $monto;
            }
            if ($montoRegla > 0) {
                $aplicadas[] = ['promocion_id' => $regla['promocion_id'] ?? null, 'nombre' => $regla['nombre'], 'monto' => $montoRegla];
            }
        }

        // Paso 2: al total, sobre lo que queda; todas calculadas sobre la misma base y sumadas.
        $restantes = [];
        foreach ($lineas as $clave => $linea) {
            $restantes[$clave] = max(0.0, ($linea['precio_unitario'] + $linea['extras_unitario']) * $linea['cantidad'] - $porLinea[$clave]);
        }
        $baseTotal = array_sum($restantes);
        $pendienteTotal = $baseTotal;
        $montoAlTotal = 0.0;
        foreach ($reglas as $regla) {
            if ($regla['alcance'] !== 'total' || $pendienteTotal <= 0) {
                continue;
            }
            $monto = $regla['tipo_valor'] === 'porcentaje'
                ? round($baseTotal * $regla['valor'] / 100)
                : round($regla['valor']);
            $monto = max(0.0, min($pendienteTotal, $monto));
            if ($monto > 0) {
                $pendienteTotal -= $monto;
                $montoAlTotal += $monto;
                $aplicadas[] = ['promocion_id' => $regla['promocion_id'] ?? null, 'nombre' => $regla['nombre'], 'monto' => $monto];
            }
        }

        // Reparto proporcional; la última línea con saldo absorbe el redondeo.
        if ($montoAlTotal > 0 && $baseTotal > 0) {
            $repartido = 0.0;
            $claves = array_keys(array_filter($restantes, fn ($r) => $r > 0));
            foreach ($claves as $i => $clave) {
                $parte = $i === count($claves) - 1
                    ? $montoAlTotal - $repartido
                    : round($montoAlTotal * $restantes[$clave] / $baseTotal);
                $parte = min($parte, $restantes[$clave]);
                $porLinea[$clave] += $parte;
                $repartido += $parte;
            }
        }

        return [
            'por_linea' => $porLinea,
            'total' => array_sum($porLinea),
            'aplicadas' => $aplicadas,
        ];
    }

    private function coincide(array $regla, array $linea): bool
    {
        if ($linea['es_combo'] || ! $linea['producto_id']) {
            return false;
        }

        if ($regla['alcance'] !== 'producto') {
            return in_array((int) $linea['categoria_id'], $regla['categorias'] ?? [], true);
        }

        // Mejora (16/10/2026): una promoción de una sola presentación no rebaja las demás. Las fotos de
        // reglas guardadas antes en un pedido no traen `variante_id`: aplican a todas, como entonces.
        $variante = $regla['variante_id'] ?? null;

        return in_array((int) $linea['producto_id'], $regla['productos'] ?? [], true)
            && ($variante === null || (int) ($linea['variante_id'] ?? 0) === (int) $variante);
    }
}
