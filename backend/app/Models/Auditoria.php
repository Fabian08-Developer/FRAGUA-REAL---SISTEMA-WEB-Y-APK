<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Auditoria extends Model
{
    public $timestamps = false; // solo created_at, definido en la migración

    protected $fillable = ['user_id', 'accion', 'entidad', 'entidad_id', 'detalles', 'ip', 'user_agent'];

    protected $casts = [
        'detalles' => 'array',
        'created_at' => 'datetime',
    ];

    // RNF-TRANS-022, RN-USR-13 (Fase 0, tarea 7): el historial de auditoría no se edita ni
    // se borra desde la aplicación. Cualquier intento lanza una excepción.
    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Un registro de auditoría no se puede modificar (RNF-TRANS-022).'));
        static::deleting(fn () => throw new \LogicException('Un registro de auditoría no se puede borrar (RNF-TRANS-022).'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
