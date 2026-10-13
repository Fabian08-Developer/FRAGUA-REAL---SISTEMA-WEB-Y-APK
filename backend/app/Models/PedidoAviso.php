<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fase 4, tarea 5 (RF-M07-R-012): un aviso enviado (o que falló) al cliente de un pedido. Hoy
 * «listo para recoger» (Fase 4) y el comprobante digital en PDF (Fase 5).
 */
class PedidoAviso extends Model
{
    // Fase 5, tarea 1: 'comprobante' = el comprobante digital en PDF (RF-M06-010).
    public const TIPOS = ['listo_para_recoger', 'comprobante'];

    protected $table = 'pedido_avisos';

    protected $fillable = ['pedido_id', 'tipo', 'canal', 'destino', 'estado', 'intentos', 'error', 'enviado_at'];

    protected $casts = ['enviado_at' => 'datetime', 'intentos' => 'integer'];

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
    }
}
