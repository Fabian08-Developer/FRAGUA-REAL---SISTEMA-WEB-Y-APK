<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fase 6, tarea 2 (RN-PED-06/09/17 a 19, D5): lo que la cocina recibe de un pedido. Su estado de
 * impresión es independiente del estado del pedido. Ver ComandaService.
 */
class Comanda extends Model
{
    public const TIPOS = ['inicial', 'actualizacion', 'cancelacion'];
    public const POR_IMPRIMIR = ['pendiente', 'fallida'];

    protected $fillable = [
        'pedido_id', 'numero', 'tipo', 'clave', 'contenido', 'estado_impresion', 'intentos',
        'impresiones', 'impresa_at', 'impresa_por', 'ultimo_error', 'modificacion_hasta',
    ];

    // La clave de idempotencia es interna y el contenido solo se usa para dibujar el ticket.
    protected $hidden = ['clave', 'modificacion_hasta', 'contenido'];

    protected $casts = [
        'contenido' => 'array',
        'intentos' => 'integer',
        'impresiones' => 'integer',
        'impresa_at' => 'datetime',
    ];

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
    }

    public function impresaPorUsuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impresa_por');
    }

    public function porImprimir(): bool
    {
        return in_array($this->estado_impresion, self::POR_IMPRIMIR, true);
    }
}
