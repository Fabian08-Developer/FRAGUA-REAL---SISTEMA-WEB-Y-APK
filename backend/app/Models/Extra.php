<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Extra extends Model
{
    protected $fillable = ['nombre', 'precio', 'alcance', 'producto_id', 'activo'];

    protected $casts = [
        'activo' => 'boolean',
        'precio' => 'decimal:2',
    ];

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function esGeneral(): bool
    {
        return $this->alcance === 'general';
    }

    // Fase 2, tarea 3 (RF-M04-011): productos a los que el Administrador asoció este extra general.
    public function productos(): BelongsToMany
    {
        return $this->belongsToMany(Producto::class, 'producto_extra')->withTimestamps();
    }

    /**
     * Si este extra puede acompañar a ese producto (null = línea de combo, sin producto propio).
     * - Específico (RN-M04-05): solo con su propio producto.
     * - General: con los productos a los que se asoció; si no se asoció a ninguno, con todo el menú.
     */
    public function aplicaA(?int $productoId): bool
    {
        if (! $this->activo) {
            return false;
        }
        if (! $this->esGeneral()) {
            return $productoId !== null && (int) $this->producto_id === $productoId;
        }
        $asociados = $this->relationLoaded('productos') ? $this->productos->pluck('id') : $this->productos()->pluck('productos.id');

        return $asociados->isEmpty() || ($productoId !== null && $asociados->contains($productoId));
    }
}
