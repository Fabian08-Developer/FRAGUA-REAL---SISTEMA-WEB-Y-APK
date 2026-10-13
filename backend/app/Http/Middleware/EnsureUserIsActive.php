<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RF-M01-006, RNF-M01-015 (Fase 0, tarea 6, 09/10/2026): una cuenta desactivada no opera,
 * en ninguna ruta con sesión. Antes solo lo revisaba el middleware de rol, así que /auth/me,
 * /perfil y /perfil/password seguían respondiendo a un usuario desactivado.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->activo) {
            // Se cierra la sesión que intentó entrar, para que el cliente deje de usarla.
            $user->currentAccessToken()?->delete();
            abort(403, 'Tu cuenta está desactivada.');
        }

        return $next($request);
    }
}
