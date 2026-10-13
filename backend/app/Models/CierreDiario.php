<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CierreDiario extends Model
{
    // CORREGIDO: sin esto, Eloquent infiere la tabla por defecto como "cierre_diarios"
    // (solo pluraliza la última palabra del nombre de la clase) — la migración real creó
    // "cierres_diarios" (2026_01_01_000020_create_cierres_diarios_table.php), así que
    // CUALQUIER consulta contra este modelo fallaba con "undefined table" en Postgres.
    protected $table = 'cierres_diarios';

    protected $fillable = [
        'fecha', 'total_ventas', 'total_compras', 'total_gastos',
        'resultado_operativo', 'cerrado_por', 'detalle', 'caja_turno_id',
    ];

    protected $casts = [
        'fecha' => 'date',
        'total_ventas' => 'decimal:2',
        'total_compras' => 'decimal:2',
        'total_gastos' => 'decimal:2',
        'resultado_operativo' => 'decimal:2',
        'detalle' => 'array',
    ];

    // Mismo nombre colisionaba con la columna 'cerrado_por' al serializar (ver el mismo
    // arreglo en CompraGasto::registradoPorUsuario()) — se corrige aquí antes de que algo
    // llegue a hacer ->with('cerradoPor').
    public function cerradoPorUsuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cerrado_por');
    }

    // Fase 2, tarea 5 (RF-M03-20, HU-M03-09): correcciones autorizadas después del cierre.
    public function ajustes(): HasMany
    {
        return $this->hasMany(CierreAjuste::class, 'cierre_diario_id')->orderBy('id');
    }

    public function cajaTurno(): BelongsTo
    {
        return $this->belongsTo(CajaTurno::class);
    }
}
