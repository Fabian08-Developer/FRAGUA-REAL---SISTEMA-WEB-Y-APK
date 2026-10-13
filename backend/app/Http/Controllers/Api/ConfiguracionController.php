<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Configuracion\UpdateConfiguracionesRequest;
use App\Models\Configuracion;
use App\Services\AuditoriaService;
use App\Services\EstadoSistemaService;

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

    // Fase 4, tarea 1: ¿están corriendo el programador y la cola en el servidor? Solo lectura.
    public function estadoSistema(EstadoSistemaService $estado)
    {
        return response()->json($estado->estado());
    }

    // Endurecimiento (13/10/2026): revisión antes de producción, la misma de
    // `php artisan fragua:revisar-produccion`. Solo lectura; no muestra secretos.
    public function revisionProduccion(\App\Services\RevisionProduccionService $revision)
    {
        $items = $revision->revisar();

        return response()->json(['items' => $items, 'listo' => ! $revision->hayErrores($items)]);
    }

    public function update(UpdateConfiguracionesRequest $request)
    {
        $cambios = [];
        foreach ($request->validated() as $clave => $valor) {
            $antes = Configuracion::obtener($clave);
            Configuracion::fijar($clave, (string) $valor);
            if ($antes !== (string) $valor) {
                $cambios[$clave] = ['antes' => $antes, 'despues' => (string) $valor];
            }
        }

        // RF-M01-017 (Fase 3, tarea 1): cambiar el valor del domicilio o del depósito es una
        // acción administrativa crítica; queda el antes y el después de lo que cambió.
        if ($cambios) {
            app(AuditoriaService::class)->registrar('editar_configuracion', 'Configuracion', null, ['cambios' => $cambios], $request->user());
        }

        return response()->json(Configuracion::query()->orderBy('clave')->get());
    }
}
