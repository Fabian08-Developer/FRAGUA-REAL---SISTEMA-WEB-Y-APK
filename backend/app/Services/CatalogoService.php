<?php

namespace App\Services;

use App\Models\Combo;
use App\Models\Extra;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * M04 — Fase 2, tarea 3 (12/10/2026): un catálogo coherente en todos los canales.
 *
 *  - Extras (RF-M04-010/011, HU-M04-004): cada producto trae `extras_disponibles`, la misma lista
 *    para el Menú, el QR de mesa y el pedido del Mesero: sus extras específicos activos más los
 *    generales activos que el Administrador le asoció (un general sin asociar no se ofrece con
 *    ningún producto, CA-M04-004). PedidoService valida con la misma regla
 *    (Extra::aplicaA), así lo que se ofrece es exactamente lo que se acepta.
 *  - Bajas (RF-M04-027, RN-M04-12): desactivar un producto desactiva los combos que lo usan; el
 *    combo no se puede reactivar mientras tenga un componente dado de baja.
 *  - Auditoría (RF-M04-022): crear o editar categorías, productos, variantes, extras y combos queda
 *    registrado con quién, cuándo y qué cambió (incluido el precio).
 */
class CatalogoService
{
    /** Agrega `extras_disponibles` (activos y aplicables) a cada producto. */
    public function conExtrasDisponibles(Collection $productos): Collection
    {
        $productos->loadMissing(['extrasEspecificos', 'extrasGenerales']);

        return $productos->each(function (Producto $p) {
            $extras = $p->extrasEspecificos->where('activo', true)
                ->concat($p->extrasGenerales->where('activo', true))
                ->unique('id')->sortBy('nombre')->values()
                ->map(fn (Extra $e) => $e->only(['id', 'nombre', 'precio', 'alcance', 'producto_id', 'activo']));
            $p->setAttribute('extras_disponibles', $extras->all());
        });
    }

    /**
     * RF-M04-027, RN-M04-12: el producto se dio de baja → sus combos activos se desactivan.
     *
     * @return array<int, string> nombres de los combos desactivados
     */
    public function desactivarCombosDe(Producto $producto, ?User $actor): array
    {
        $combos = $producto->combos()->where('activo', true)->get();
        foreach ($combos as $combo) {
            $combo->activo = false;
            $combo->save();
            app(AuditoriaService::class)->registrar('desactivar_combo', 'Combo', $combo->id, [
                'nombre' => $combo->nombre,
                'motivo' => "Se dio de baja su componente \"{$producto->nombre}\" (RN-M04-12).",
            ], $actor);
        }

        return $combos->pluck('nombre')->all();
    }

    /** Componentes dados de baja que impiden activar el combo (RN-M04-12). */
    public function componentesInactivos(array $productoIds): Collection
    {
        return Producto::whereIn('id', $productoIds)->where('activo', false)->pluck('nombre');
    }

    /** RF-M04-022: alta o edición de un elemento del catálogo, con lo que cambió. */
    public function auditar(string $accion, Model $modelo, ?array $antes, ?User $actor, array $extra = []): void
    {
        $cambios = [];
        if ($antes !== null) {
            foreach ($modelo->getChanges() as $campo => $valor) {
                if (in_array($campo, ['updated_at', 'created_at'], true)) {
                    continue;
                }
                $cambios[$campo] = ['antes' => $antes[$campo] ?? null, 'despues' => $valor];
            }
            if (! $cambios && ! $extra) {
                return; // guardar sin cambios no deja ruido en la auditoría
            }
        }

        app(AuditoriaService::class)->registrar($accion, class_basename($modelo), $modelo->getKey(), array_filter([
            'nombre' => $modelo->getAttribute('nombre'),
            'cambios' => $cambios ?: null,
            'datos' => $antes === null ? array_diff_key($modelo->getAttributes(), array_flip(['created_at', 'updated_at'])) : null,
        ] + $extra, fn ($v) => $v !== null), $actor);
    }
}
