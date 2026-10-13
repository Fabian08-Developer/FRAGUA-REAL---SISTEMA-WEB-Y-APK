<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * M08 — Fase 5, tarea 3 (RF-M08-011/012, RNF-M08-006): un reporte exportado y guardado. El archivo
 * vive en el disco privado (`archivo`, nunca viaja en el JSON) y se descarga tal cual se generó.
 */
class ReporteExportacion extends Model
{
    protected $table = 'reporte_exportaciones';

    protected $fillable = [
        'reporte', 'formato', 'desde', 'hasta', 'parcial', 'comparar',
        'archivo', 'nombre_archivo', 'bytes', 'generado_por', 'descargas',
    ];

    protected $hidden = ['archivo'];

    protected $casts = [
        'desde' => 'date:Y-m-d',
        'hasta' => 'date:Y-m-d',
        'parcial' => 'boolean',
        'comparar' => 'boolean',
        'bytes' => 'integer',
        'descargas' => 'integer',
    ];

    public function generadoPorUsuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generado_por');
    }
}
