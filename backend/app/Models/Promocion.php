<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Promocion extends Model
{
    // BUG corregido junto con esta tarea: sin esto, Eloquent pluraliza "Promocion" al
    // inglés ("promocions") en vez de usar la tabla real "promociones" (migración
    // create_promociones_table) — nunca se había notado porque no existía Controller que
    // usara este modelo hasta ahora.
    protected $table = 'promociones';

    protected $fillable = [
        'nombre', 'tipo', 'alcance', 'tipo_valor', 'valor',
        'vigencia_inicio', 'vigencia_fin', 'franja_inicio', 'franja_fin',
        'dias_semana', 'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'valor' => 'decimal:2',
        'vigencia_inicio' => 'date',
        'vigencia_fin' => 'date',
        'dias_semana' => 'array',
    ];

    public function productos(): BelongsToMany
    {
        return $this->belongsToMany(Producto::class, 'promocion_producto');
    }

    public function categorias(): BelongsToMany
    {
        return $this->belongsToMany(Categoria::class, 'promocion_categoria');
    }

    public function esAutomatica(): bool
    {
        return $this->tipo === 'automatica';
    }
}
