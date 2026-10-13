<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Fase 6, tarea 1 (RF-M06-006): un intento de pago en la pasarela (Wompi), del pago de un pedido
 * o del depósito de una reserva. Ver PasarelaPagoService.
 */
class TransaccionPasarela extends Model
{
    protected $table = 'transacciones_pasarela';

    public const FINALES = ['aprobado', 'rechazado', 'anulado', 'error', 'abandonado'];

    protected $fillable = [
        'proveedor', 'referencia', 'pagable_type', 'pagable_id', 'monto', 'moneda', 'pasarela_preferida',
        'estado', 'transaccion_id', 'metodo_pago', 'estado_proveedor', 'respuesta', 'eventos',
        'ultimo_evento_at', 'expira_at', 'finalizada_at', 'requiere_revision', 'motivo_revision',
        'revisado_por', 'revisado_at', 'reembolso_estado', 'reembolso_id', 'reembolso_mensaje', 'reembolso_respuesta', 'reembolso_at',
    ];

    // La respuesta completa de la pasarela no viaja al navegador (trae datos del pagador).
    protected $hidden = ['respuesta', 'reembolso_respuesta'];

    protected $casts = [
        'monto' => 'decimal:2',
        'respuesta' => 'array',
        'reembolso_respuesta' => 'array',
        'requiere_revision' => 'boolean',
        'ultimo_evento_at' => 'datetime',
        'expira_at' => 'datetime',
        'finalizada_at' => 'datetime',
        'revisado_at' => 'datetime',
        'reembolso_at' => 'datetime',
    ];

    public function pagable(): MorphTo
    {
        return $this->morphTo();
    }

    public function revisadoPorUsuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revisado_por');
    }

    public function esFinal(): bool
    {
        return in_array($this->estado, self::FINALES, true);
    }

    public function montoEnCentavos(): int
    {
        return (int) round((float) $this->monto * 100);
    }
}
