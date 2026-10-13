<?php

namespace App\Exceptions;

use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Fase 2, tarea 5 — RN-M03-08, RF-M03-20: el cambio toca un día ya cerrado y necesita que el
 * Administrador confirme con su contraseña y escriba el motivo. Responde 422 con
 * `requiere_autorizacion: true`, para que la pantalla pida esos dos datos y reintente.
 */
class AutorizacionRequerida extends HttpResponseException
{
    public function __construct(string $mensaje, array $extra = [])
    {
        parent::__construct(response()->json(['message' => $mensaje, 'requiere_autorizacion' => true] + $extra, 422));
    }
}
