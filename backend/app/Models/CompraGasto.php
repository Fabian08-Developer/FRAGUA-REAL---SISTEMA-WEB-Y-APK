<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompraGasto extends Model
{
    protected $table = 'compras_gastos';

    protected $fillable = [
        'tipo', 'categoria', 'descripcion', 'valor', 'metodo_pago',
        'proveedor', 'numero_factura', 'soporte_url', 'fecha',
        'registrado_por', 'estado', 'anulado_motivo',
    ];

    protected $casts = [
        'valor' => 'decimal:2',
        'fecha' => 'date',
    ];

    // CORREGIDO: se llamaba registradoPor() — Eloquent serializa el nombre de una relación
    // en snake_case (Str::snake), así que "registradoPor" se convertía en la llave JSON
    // "registrado_por", la MISMA que la columna FK cruda de abajo — al cargarla con
    // ->with(), la relación pisaba el entero crudo en la respuesta. Mismo bug ya corregido
    // antes en CajaTurno (abiertoPorUsuario/cerradoPorUsuario) y Pago
    // (registradoPorUsuario/anuladoPorUsuario) — mismo patrón de nombre aquí.
    public function registradoPorUsuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    public function estaActivo(): bool
    {
        return $this->estado === 'activo';
    }
}
