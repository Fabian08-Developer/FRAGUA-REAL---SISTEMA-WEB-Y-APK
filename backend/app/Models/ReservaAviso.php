<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fase 4, tarea 4 (RF-M05-027): un aviso enviado (o que falló) al cliente de una reserva.
 */
class ReservaAviso extends Model
{
    public const TIPOS = ['confirmacion', 'recordatorio', 'cancelacion', 'no_show'];

    protected $table = 'reserva_avisos';

    protected $fillable = ['reserva_id', 'tipo', 'canal', 'destino', 'estado', 'intentos', 'error', 'enviado_at'];

    protected $casts = ['enviado_at' => 'datetime', 'intentos' => 'integer'];

    public function reserva(): BelongsTo
    {
        return $this->belongsTo(Reserva::class);
    }
}
