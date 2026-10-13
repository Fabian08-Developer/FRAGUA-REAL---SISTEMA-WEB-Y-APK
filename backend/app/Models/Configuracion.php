<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Almacén clave/valor de configuraciones editables por el Administrador (RF-M07-030,
 * RN-M05-08 a 13) — reemplaza los valores que antes vivían fijos en config/fragua_real.php.
 * El valor se guarda siempre como string (los Controllers hacen el cast al tipo que
 * necesiten); obtener()/fijar() son el único punto de lectura/escritura para no repetir
 * esa conversión en cada sitio que la use.
 */
class Configuracion extends Model
{
    // CORREGIDO (29/09/2026): sin esto, Eloquent adivina la tabla en inglés
    // ("configuracions", singular "configuracion" + "s") en vez de la real
    // ("configuraciones", ver migración 2026_09_28_000001) — cualquier lectura
    // (valor_domicilio al crear un pedido de Domicilio, deposito_reserva al
    // reservar) tiraba "SQLSTATE[42P01]: Undefined table".
    protected $table = 'configuraciones';

    protected $fillable = ['clave', 'valor', 'descripcion'];

    public static function obtener(string $clave, ?string $default = null): ?string
    {
        return static::query()->where('clave', $clave)->value('valor') ?? $default;
    }

    public static function fijar(string $clave, string $valor): self
    {
        return static::query()->updateOrCreate(['clave' => $clave], ['valor' => $valor]);
    }

    // Atajo para los valores numéricos (valor_domicilio, deposito_reserva) que hoy fijan el
    // servidor al crear el Pedido/la Reserva — $default normalmente viene de
    // config('fragua_real.*') por si la tabla aún no tiene esa fila sembrada.
    public static function obtenerNumerico(string $clave, float $default = 0): float
    {
        return (float) static::obtener($clave, (string) $default);
    }
}
