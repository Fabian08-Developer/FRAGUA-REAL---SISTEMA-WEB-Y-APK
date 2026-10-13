<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Configuracion\UpdateConfiguracionesRequest;
use App\Models\Configuracion;

/**
 * M05/M07 — Valores del sistema editables por el Administrador "en cualquier momento"
 * (RF-M07-030, RN-M05-08 a 13): valor de domicilio y depósito de reserva. Antes vivían
 * fijos en config/fragua_real.php; ahora viven en la tabla `configuraciones` para que el
 * Administrador pueda cambiarlos desde el sistema, sin tocar código ni redesplegar.
 *
 * El endpoint público de solo lectura que consumen Checkout y Reservar
 * (CatalogoController::configuracion(), GET /configuracion) lee de esta tabla, con
 * config/fragua_real.php como valor de respaldo si la fila aún no existe. La pantalla del
 * Administrador (views/admin/ConfiguracionView.vue) lee con ese GET y guarda con
 * update() (PUT /configuraciones).
 */
class ConfiguracionController extends Controller
{
    public function index()
    {
        return response()->json(Configuracion::query()->orderBy('clave')->get());
    }

    public function update(UpdateConfiguracionesRequest $request)
    {
        foreach ($request->validated() as $clave => $valor) {
            Configuracion::fijar($clave, (string) $valor);
        }

        return response()->json(Configuracion::query()->orderBy('clave')->get());
    }
}
