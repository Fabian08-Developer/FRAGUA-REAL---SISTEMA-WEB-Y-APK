<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Reserva extends Model
{
    protected $fillable = [
        'mesa_id', 'cliente_nombre', 'cliente_telefono', 'cliente_correo', 'fecha_hora',
        'estado', 'deposito_valor', 'deposito_pagado', 'deposito_metodo',
        'deposito_pasarela', 'deposito_estado', 'deposito_pagado_at',
        'deposito_reembolsado', 'deposito_aplicado_abono', 'deposito_registrado_por',
        'creado_por', 'registrada_por',
        'pedido_id', 'deposito_abonado', 'llegada_at', 'deposito_caja_turno_id', 'deposito_reembolso_caja_turno_id',
        'no_show_at', 'no_show_automatico', 'recordatorio_enviado_at',
    ];

    // Fase 4, tarea 4: el enlace secreto de la reserva solo viaja en los correos al cliente.
    protected $hidden = ['token_publico'];

    protected static function booted(): void
    {
        static::creating(function (Reserva $reserva) {
            $reserva->token_publico ??= Str::random(48);
        });
    }

    protected $casts = [
        'fecha_hora' => 'datetime',
        'deposito_pagado' => 'boolean',
        'deposito_reembolsado' => 'boolean',
        'deposito_aplicado_abono' => 'boolean',
        'deposito_pagado_at' => 'datetime',
        'deposito_valor' => 'decimal:2',
        'deposito_abonado' => 'decimal:2',
        'llegada_at' => 'datetime',
        // Fase 4, tarea 3 (RF-M05-016/024)
        'no_show_at' => 'datetime',
        'no_show_automatico' => 'boolean',
        'recordatorio_enviado_at' => 'datetime',
    ];


    public function mesa(): BelongsTo
    {
        return $this->belongsTo(Mesa::class);
    }

    // Fase 4, tarea 4 (RF-M05-027): avisos enviados al cliente, del más reciente al más antiguo.
    public function avisos(): HasMany
    {
        return $this->hasMany(ReservaAviso::class)->latest('id');
    }

    // Enlace público para ver y cancelar la reserva sin cuenta (RF-M05-018, RN-M05-09).
    public function enlacePublico(): string
    {
        return rtrim((string) config('fragua_real.frontend_url'), '/').'/reserva/'.$this->token_publico;
    }

    // Fase 2, tarea 2 (RF-M05-034): primer pedido de la visita al que se abonó el depósito.
    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
    }

    // Lo que queda del depósito por abonar a los pedidos de la visita.
    public function depositoPorAbonar(): float
    {
        if (! $this->deposito_pagado || $this->deposito_reembolsado) {
            return 0.0;
        }

        return max(0.0, round((float) $this->deposito_valor - (float) $this->deposito_abonado, 2));
    }

    // Quién registró el depósito manual (Mesero o Administrador, RN-M05-16); nulo si el
    // depósito se pagó en línea (autoservicio del Cliente).
    public function depositoRegistradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deposito_registrado_por');
    }

    // RF-M05-013 (Fase 1): quién registró una reserva manual (Mesero o Administrador).
    // Nombre largo para no chocar con la columna al serializar (ver la nota en Pago.php).
    public function registradaPorUsuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrada_por');
    }

    // Reservas que ocupan su mesa: confirmadas, y las pendientes que ya están en camino (un pago
    // en línea en revisión, o una reserva manual del personal esperando su depósito). Una reserva
    // web que nunca empezó a pagar no aparta la mesa.
    public function scopeQueApartanMesa($query)
    {
        return $query->where(function ($q) {
            $q->where('estado', 'confirmada')
                ->orWhere(fn ($p) => $p->where('estado', 'pendiente_pago')->where(
                    fn ($x) => $x->where('deposito_estado', 'pendiente')->orWhere('creado_por', '!=', 'cliente_web')
                ));
        });
    }
}
