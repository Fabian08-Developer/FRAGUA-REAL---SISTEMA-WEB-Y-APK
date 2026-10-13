<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fase 2, tarea 5 — RF-M03-20, RN-M03-08/15, HU-M03-09: una corrección autorizada a un día ya
 * cerrado. No se edita ni se borra: es la traza de lo que cambió después del cierre.
 */
class CierreAjuste extends Model
{
    protected $table = 'cierre_ajustes';

    protected $fillable = [
        'cierre_diario_id', 'compra_gasto_id', 'accion', 'delta_compras', 'delta_gastos',
        'detalle', 'motivo', 'autorizado_por',
    ];

    protected $casts = [
        'delta_compras' => 'decimal:2',
        'delta_gastos' => 'decimal:2',
        'detalle' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }

    public function cierre(): BelongsTo
    {
        return $this->belongsTo(CierreDiario::class, 'cierre_diario_id');
    }

    public function compraGasto(): BelongsTo
    {
        return $this->belongsTo(CompraGasto::class);
    }

    public function autorizadoPorUsuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autorizado_por');
    }
}
