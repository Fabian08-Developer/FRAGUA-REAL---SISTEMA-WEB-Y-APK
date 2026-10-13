<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * RF-M06-019, RN-M06-10: ajuste extraordinario. Se crea cuando el Administrador anula o
 * reembolsa un pago de un día cuya caja ya cerró. El turno cerrado conserva sus cifras
 * (lo que se contó ese día no cambia); el ajuste deja constancia aparte de quién lo autorizó,
 * por cuánto y por qué.
 */
class AjusteCaja extends Model
{
    protected $table = 'ajustes_caja';

    protected $fillable = ['caja_turno_id', 'pago_id', 'tipo', 'metodo', 'monto', 'motivo', 'autorizado_por'];

    protected $casts = [
        'monto' => 'decimal:2',
    ];

    public function cajaTurno(): BelongsTo
    {
        return $this->belongsTo(CajaTurno::class);
    }

    public function pago(): BelongsTo
    {
        return $this->belongsTo(Pago::class);
    }

    // "...PorUsuario" por la misma razón que en Pago y CajaTurno: evita que la relación
    // serializada pise la columna autorizado_por.
    public function autorizadoPorUsuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autorizado_por');
    }
}
