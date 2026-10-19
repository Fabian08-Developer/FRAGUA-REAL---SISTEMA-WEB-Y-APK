<?php

namespace App\Services;

use App\Models\Producto;
use App\Models\Promocion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Mejora (16/10/2026): atajo «Poner en oferta» del Catálogo.
 *
 * Poner un producto en oferta tomaba crear una promoción completa (nombre, tipo, alcance, producto,
 * valor, vigencia). El atajo pide solo el precio de oferta o el porcentaje, hasta qué día y, si el
 * producto tiene presentaciones, a cuál aplica. Por dentro es una promoción automática de producto
 * (RF-M04-033) marcada con `es_oferta`, así se ve igual en el Menú, el QR y el pedido del Mesero, se
 * combina con las demás promociones (RN-M04-20), la anula un descuento manual (RN-M04-21) y queda en
 * la auditoría (RF-M04-039) y en los reportes.
 *
 * Un producto tiene una sola oferta a la vez: poner otra desactiva la anterior.
 */
class OfertaService
{
    public function __construct(
        private readonly AuditoriaService $auditoria,
        private readonly PromocionService $promociones,
    ) {
    }

    /**
     * @param  array{modo: string, precio_oferta?: float|string|null, porcentaje?: float|string|null, variante_id?: int|null, hasta?: string|null}  $datos
     */
    public function poner(Producto $producto, array $datos, ?User $actor): Promocion
    {
        $variante = ! empty($datos['variante_id']) ? $producto->variantes()->findOrFail($datos['variante_id']) : null;

        if ($datos['modo'] === 'precio') {
            $referencia = (float) ($variante?->precio ?? $producto->precio_base);
            $tipoValor = 'fijo';
            $valor = round($referencia - (float) $datos['precio_oferta'], 2);
        } else {
            $tipoValor = 'porcentaje';
            $valor = round((float) $datos['porcentaje'], 2);
        }

        return DB::transaction(function () use ($producto, $variante, $tipoValor, $valor, $datos, $actor) {
            $reemplazadas = $this->desactivar($producto);

            $promocion = Promocion::create([
                'nombre' => 'Oferta: '.$producto->nombre.($variante ? " ({$variante->nombre})" : ''),
                'tipo' => 'automatica',
                'alcance' => 'producto',
                'variante_id' => $variante?->id,
                'tipo_valor' => $tipoValor,
                'valor' => $valor,
                'vigencia_inicio' => PromocionService::enHoraDelNegocio()->toDateString(),
                'vigencia_fin' => $datos['hasta'] ?? null,
                'activo' => true,
                'es_oferta' => true,
            ]);
            $promocion->productos()->sync([$producto->id]);

            $this->auditoria->registrar('poner_oferta', 'Promocion', $promocion->id, array_filter([
                'producto' => $producto->nombre,
                'presentacion' => $variante?->nombre,
                'precio_oferta' => $datos['modo'] === 'precio' ? (float) $datos['precio_oferta'] : null,
                'porcentaje' => $datos['modo'] === 'porcentaje' ? $valor : null,
                'hasta' => $datos['hasta'] ?? null,
                'reemplaza' => $reemplazadas ?: null,
            ], fn ($v) => $v !== null), $actor);

            return $promocion->load(['productos:id,nombre', 'variante:id,nombre']);
        });
    }

    /** Quita la oferta del producto (la promoción queda inactiva, con su historial). */
    public function quitar(Producto $producto, ?User $actor): int
    {
        return DB::transaction(function () use ($producto, $actor) {
            $quitadas = $this->desactivar($producto);
            if ($quitadas) {
                $this->auditoria->registrar('quitar_oferta', 'Producto', $producto->id, [
                    'producto' => $producto->nombre,
                    'promociones' => $quitadas,
                ], $actor);
            }

            return count($quitadas);
        });
    }

    /**
     * La oferta activa de cada producto (vigente hoy o que empieza más adelante), para el Catálogo.
     *
     * @param  array<int, int>  $productoIds
     * @return array<int, array<string, mixed>>
     */
    public function ofertasPorProducto(array $productoIds): array
    {
        if (! $productoIds) {
            return [];
        }
        $hoy = PromocionService::enHoraDelNegocio()->toDateString();

        return Promocion::with(['productos:id', 'variante:id,nombre'])
            ->where('es_oferta', true)->where('activo', true)
            ->where(fn ($q) => $q->whereNull('vigencia_fin')->orWhereDate('vigencia_fin', '>=', $hoy))
            ->whereHas('productos', fn ($q) => $q->whereIn('productos.id', $productoIds))
            ->orderBy('id')->get()
            ->mapWithKeys(fn (Promocion $p) => [(int) $p->productos->first()->id => [
                'promocion_id' => $p->id,
                'tipo_valor' => $p->tipo_valor,
                'valor' => (float) $p->valor,
                'variante_id' => $p->variante_id,
                'variante' => $p->variante?->nombre,
                'hasta' => $p->vigencia_fin?->toDateString(),
                'vigente' => $this->promociones->vigente($p),
            ]])
            ->all();
    }

    /** @return array<int, int> ids de las ofertas que se desactivaron */
    private function desactivar(Producto $producto): array
    {
        $ofertas = Promocion::where('es_oferta', true)->where('activo', true)
            ->whereHas('productos', fn ($q) => $q->where('productos.id', $producto->id))
            ->get();
        foreach ($ofertas as $oferta) {
            $oferta->update(['activo' => false]);
        }

        return $ofertas->pluck('id')->all();
    }
}
