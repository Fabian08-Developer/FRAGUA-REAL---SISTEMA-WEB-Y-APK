<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Filtra por rol a nivel de ruta (RNF-TRANS-011) — responde "¿este rol puede llegar aquí en
 * general?". La autorización fina sobre un registro puntual vive en las Policies.
 * Uso: ->middleware('role:administrador') o ->middleware('role:mesero,administrador')
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        // M09 (19/10/2026): donde entra el Administrador también entra el SuperAdmin.
        // M10 (20/10/2026): y la cuenta de Soporte, que solo consulta (middleware `soporte`).
        if (in_array('administrador', $roles, true)) {
            $roles[] = 'superadmin';
            $roles[] = 'soporte';
        }

        if (! $user || ! $user->activo || ! in_array($user->role, $roles, true)) {
            abort(403, 'No tienes permiso para acceder a este recurso.');
        }

        return $next($request);
    }
}
