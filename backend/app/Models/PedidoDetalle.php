<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PedidoDetalle extends Model
{
    protected $table = 'pedido_detalles';

    protected $fillable = [
        'pedido_id', 'producto_id', 'variante_id', 'combo_id',
        'cantidad', 'precio_unitario_congelado', 'descuento', 'preparado', 'observaciones',
    ];

    protected $casts = [
        'preparado' => 'boolean',
        'precio_unitario_congelado' => 'decimal:2',
        'descuento' => 'decimal:2',
    ];

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function variante(): BelongsTo
    {
        return $this->belongsTo(Variante::class);
    }

    public function combo(): BelongsTo
    {
        return $this->belongsTo(Combo::class);
    }

    public function extras(): BelongsToMany
    {
        return $this->belongsToMany(Extra::class, 'pedido_detalle_extra')->withPivot('precio_congelado');
    }

    // Antes de descuentos: cantidad × (precio congelado + extras congelados).
    public function subtotalLinea(): float
    {
        $extras = $this->extras->sum('pivot.precio_congelado');
        return ((float) $this->precio_unitario_congelado + $extras) * $this->cantidad;
    }

    // Fase 2, tarea 1: lo que realmente se cobra por la línea, con su parte del descuento.
    public function totalLinea(): float
    {
        return $this->subtotalLinea() - (float) $this->descuento;
    }
}
