<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fase 4, tarea 5 (RF-M07-R-012): un aviso enviado (o que falló) al cliente de un pedido. Hoy
 * solo existe «listo para recoger»; el comprobante digital (Fase 5) puede sumarse aquí.
 */
class PedidoAviso extends Model
{
    public const TIPOS = ['listo_para_recoger'];

    protected $table = 'pedido_avisos';

    protected $fillable = ['pedido_id', 'tipo', 'canal', 'destino', 'estado', 'intentos', 'error', 'enviado_at'];

    protected $casts = ['enviado_at' => 'datetime', 'intentos' => 'integer'];

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
    }
}
