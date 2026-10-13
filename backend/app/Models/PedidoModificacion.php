<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Historial de cambios a las líneas de un pedido (RF-M02-L-017, RN-PED-12, RF-M07-028).
 * Igual que la auditoría, un registro no se edita ni se borra.
 */
class PedidoModificacion extends Model
{
    protected $table = 'pedido_modificaciones';

    protected $fillable = [
        'pedido_id', 'user_id', 'accion', 'pedido_detalle_id', 'estado_pedido',
        'descripcion', 'antes', 'despues', 'total_anterior', 'total_nuevo', 'afecta_comanda',
    ];

    protected $casts = [
        'antes' => 'array',
        'despues' => 'array',
        'total_anterior' => 'decimal:2',
        'total_nuevo' => 'decimal:2',
        'afecta_comanda' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('El historial de un pedido no se edita.'));
        static::deleting(fn () => throw new \LogicException('El historial de un pedido no se borra.'));
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
