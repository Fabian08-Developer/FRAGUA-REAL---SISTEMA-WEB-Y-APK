<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Producto extends Model
{
    protected $fillable = [
        'categoria_id', 'nombre', 'descripcion', 'precio_base',
        'tipo_venta', 'disponible', 'imagen_url', 'activo',
    ];

    protected $casts = [
        'disponible' => 'boolean',
        'activo' => 'boolean',
        'precio_base' => 'decimal:2',
    ];

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class);
    }

    public function variantes(): HasMany
    {
        return $this->hasMany(Variante::class);
    }

    // Extras específicos de este producto (RN-M04-05). Los "generales" no llevan producto_id.
    public function extrasEspecificos(): HasMany
    {
        return $this->hasMany(Extra::class);
    }

    // Fase 2, tarea 3 (RF-M04-011): extras generales que el Administrador asoció a este producto.
    public function extrasGenerales(): BelongsToMany
    {
        return $this->belongsToMany(Extra::class, 'producto_extra')->where('alcance', 'general')->withTimestamps();
    }

    public function combos(): BelongsToMany
    {
        return $this->belongsToMany(Combo::class, 'combo_producto')->withPivot('cantidad');
    }

    public function promociones(): BelongsToMany
    {
        return $this->belongsToMany(Promocion::class, 'promocion_producto');
    }

    // RN-M04-08: agotado/inactivo no puede agregarse a un pedido nuevo (RF-M04-018).
    // RF-M04-003 (Fase 0, tarea 2): desactivar una categoría también saca sus productos.
    public function puedeAgregarseAPedido(): bool
    {
        return $this->activo && $this->disponible && ($this->categoria?->activo ?? true);
    }
}
