<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Variante extends Model
{
    protected $fillable = ['producto_id', 'nombre', 'precio', 'activa', 'disponible'];

    protected $casts = [
        'activa' => 'boolean',
        'disponible' => 'boolean',
        'precio' => 'decimal:2',
    ];

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    // RF-M04-018, RN-M04-08 (Fase 1): se vende si sigue en el catálogo (activa) y no está agotada.
    public function puedeVenderse(): bool
    {
        return $this->activa && ($this->disponible ?? true);
    }
}
