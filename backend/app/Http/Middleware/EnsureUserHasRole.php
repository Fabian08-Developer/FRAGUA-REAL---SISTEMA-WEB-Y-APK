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

        if (! $user || ! $user->activo || ! in_array($user->role, $roles, true)) {
            abort(403, 'No tienes permiso para acceder a este recurso.');
        }

        return $next($request);
    }
}
