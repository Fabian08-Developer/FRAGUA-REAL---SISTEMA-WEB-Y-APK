<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompraGasto extends Model
{
    protected $table = 'compras_gastos';

    protected $fillable = [
        'tipo', 'categoria', 'descripcion', 'cantidad', 'unidad', 'valor', 'metodo_pago',
        'proveedor', 'numero_factura', 'observaciones', 'soporte_url', 'fecha',
        'registrado_por', 'estado', 'anulado_motivo',
    ];

    // Fase 2, tarea 4 (RNF-M03-11): la ruta interna del soporte no viaja; el frontend usa
    // `tiene_soporte` y lo descarga por GET /compras-gastos/{id}/soporte con sesión.
    protected $hidden = ['soporte_path'];

    protected $appends = ['tiene_soporte'];

    protected $casts = [
        'valor' => 'decimal:2',
        'cantidad' => 'decimal:2',
        'fecha' => 'date',
        'editado_at' => 'datetime',
        'anulado_at' => 'datetime',
    ];

    public function getTieneSoporteAttribute(): bool
    {
        return $this->soporte_path !== null;
    }

    // RF-M03-19: quién corrigió o anuló el registro.
    public function editadoPorUsuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'editado_por');
    }

    public function anuladoPorUsuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulado_por');
    }

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
