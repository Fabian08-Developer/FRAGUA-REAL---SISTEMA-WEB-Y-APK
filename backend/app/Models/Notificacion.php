<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * M11 (21/10/2026): una notificación de la campana para una persona del personal. La crea
 * NotificacionService; la persona solo la marca como leída.
 */
class Notificacion extends Model
{
    protected $table = 'notificaciones';

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'tipo', 'categoria', 'titulo', 'texto', 'enlace', 'clave', 'leida_at'];

    protected $hidden = ['clave'];

    protected $casts = [
        'leida_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
