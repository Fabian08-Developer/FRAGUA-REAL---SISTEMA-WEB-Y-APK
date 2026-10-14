<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Endurecimiento (13/10/2026): cabeceras de seguridad en cada respuesta de la API. Nginx pone las
 * de la aplicación web (backend/deploy/nginx-fragua-real.conf); estas viajan además con la API para
 * que valgan aunque se sirva de otra forma (php artisan serve, otro proxy).
 *
 *  - nosniff: el navegador no adivina el tipo de un archivo (un PDF o un soporte no se ejecuta como script).
 *  - frame-ancestors / X-Frame-Options: la API no se puede incrustar en otra página.
 *  - CSP default-src 'none': una respuesta de la API nunca carga ni ejecuta nada.
 *  - no-referrer: las URL con enlaces secretos (seguimiento, reserva, pago) no se filtran a terceros.
 *  - no-store: datos de pedidos, pagos y documentos no quedan en cachés del navegador o de proxies.
 */
class CabecerasSeguridad
{
    public function handle(Request $request, Closure $next): Response
    {
        $respuesta = $next($request);
        if (! $request->is('api/*')) {
            return $respuesta;
        }

        $cabeceras = $respuesta->headers;
        $cabeceras->set('X-Content-Type-Options', 'nosniff');
        $cabeceras->set('X-Frame-Options', 'DENY');
        $cabeceras->set('Referrer-Policy', 'no-referrer');
        $cabeceras->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        $cabeceras->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $cabeceras->set('Cross-Origin-Resource-Policy', 'same-site');
        // Fase 7, tarea 3: las fotos de productos son públicas y llevan versión en la dirección
        // (?v=…), así que pueden guardarse en caché; todo lo demás, no.
        $esFotoPublica = $request->routeIs('productos.foto')
            && str_contains((string) $cabeceras->get('Cache-Control'), 'public');
        if (! $esFotoPublica && (! $cabeceras->has('Cache-Control') || ! str_contains((string) $cabeceras->get('Cache-Control'), 'no-store'))) {
            $cabeceras->set('Cache-Control', 'no-store, private');
        }
        $cabeceras->remove('X-Powered-By');

        return $respuesta;
    }
}
