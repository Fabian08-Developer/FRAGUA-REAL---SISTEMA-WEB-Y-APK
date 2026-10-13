<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * M06 — RF-M06-009/010, RN-M06-07 (Fase 5, tarea 1): comprobante de pago de un pedido.
 * "impreso" (ticket de 80 mm) para pedidos locales y "digital" (PDF) para domicilio y recogida.
 * `detalle` guarda lo que dice el documento al generarse; nunca se reescribe. Si deja de valer,
 * se anula (anulado_at) y el siguiente sale con otro número. Lo genera ComprobanteService.
 * `impresiones`: cuántas veces lo imprimió el personal; desde la segunda sale como REIMPRESIÓN.
 */
class Comprobante extends Model
{
    protected $fillable = ['pedido_id', 'consecutivo', 'tipo', 'numero', 'generado_at', 'detalle', 'anulado_at', 'anulado_motivo', 'impresiones'];

    // El detalle completo solo se usa para dibujar el documento; en los listados basta el resumen.
    protected $hidden = ['detalle'];

    protected $casts = [
        'generado_at' => 'datetime',
        'anulado_at' => 'datetime',
        'detalle' => 'array',
        'consecutivo' => 'integer',
        'impresiones' => 'integer',
    ];

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
    }

    public function estaVigente(): bool
    {
        return $this->anulado_at === null;
    }
}
