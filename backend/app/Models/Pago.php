<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Pago extends Model
{
    // Fase 5, tarea 1 (RN-M06-07): todo pago aprobado, anulado o ajustado pone al día el
    // comprobante de su pedido (ComprobanteService::sincronizar).
    protected static function booted(): void
    {
        static::saved(function (Pago $pago) {
            if ($pago->pedido_id) {
                app(\App\Services\ComprobanteService::class)->sincronizar($pago->pedido()->first());
            }
        });
    }

    protected $fillable = [
        'pedido_id', 'tipo', 'reserva_id', 'caja_turno_id', 'metodo', 'pasarela', 'monto', 'estado',
        'confirmado_via', 'confirmado_por', 'registrado_por', 'anulado', 'reverso_tipo', 'anulado_motivo', 'anulado_por', 'anulado_at',
    ];

    protected $casts = [
        'monto' => 'decimal:2',
        'anulado' => 'boolean',
        'anulado_at' => 'datetime',
    ];

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
    }

    public function cajaTurno(): BelongsTo
    {
        return $this->belongsTo(CajaTurno::class);
    }

    // Nombrado "...PorUsuario" (no "registradoPor"/"anuladoPor" a secas) a propósito:
    // Eloquent serializa la relación cargada usando Str::snake(nombre), y
    // Str::snake('registradoPor') === 'registrado_por' — el mismo nombre que la columna
    // FK. Con el nombre corto, cargar la relación SOBRESCRIBE el id crudo en el JSON con
    // el objeto User completo bajo la misma llave (bug real, visto en vivo al construir
    // CajaView admin: "abierto_por" salía como objeto en vez del id). Este nombre más
    // largo evita la colisión sin tocar las columnas de la tabla.
    public function registradoPorUsuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    public function anuladoPorUsuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulado_por');
    }

    // Fase 2, tarea 2 (RF-M05-034, D7): un pago de tipo "deposito" es el abono del depósito de una
    // reserva. Cuenta como pagado en el pedido, pero no es dinero de la caja de ese día: entró
    // cuando se recibió el depósito (o por la pasarela, si fue en línea).
    public function reserva(): BelongsTo
    {
        return $this->belongsTo(Reserva::class);
    }

    // Fase 6, tarea 1 (RF-M06-006): el intento en la pasarela de este pago en línea, si lo hubo
    // (en modo manual no hay ninguno).
    public function transaccionPasarela(): MorphOne
    {
        return $this->morphOne(TransaccionPasarela::class, 'pagable')->latestOfMany();
    }

    public function confirmadoPorUsuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmado_por');
    }

    public function esAbonoDeDeposito(): bool
    {
        return $this->tipo === 'deposito';
    }

    public function esEfectivo(): bool
    {
        return $this->metodo === 'efectivo';
    }
}
