<?php

namespace App\Http\Middleware;

use App\Support\Usuarios\MenusPorRol;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * M09 (20/10/2026): bloquea en el servidor las acciones de un menú que el SuperAdmin le quitó a
 * esa cuenta (RF-M09-013). Uso: ->middleware('menu:catalogo') o ->middleware('menu:mesas,reservas')
 * (basta con tener uno de los menús). Las claves del Administrador y del Mesero no se cruzan,
 * así que una misma ruta puede listar las de los dos roles. El SuperAdmin y el Cliente pasan.
 */
class EnsureMenuPermitido
{
    public function handle(Request $request, Closure $next, string ...$menus): Response
    {
        $usuario = $request->user();
        if ($usuario && ! MenusPorRol::permite($usuario, $menus)) {
            abort(403, MenusPorRol::SIN_PERMISO);
        }

        return $next($request);
    }
}
