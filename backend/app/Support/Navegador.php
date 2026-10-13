<?php

namespace App\Support;

/**
 * Texto corto y legible del navegador a partir del user agent ("Chrome en Android"). Lo usan la
 * consulta de auditoría (Fase 3, tarea 1) y la lista de sesiones abiertas (Fase 3, tarea 4).
 * Es solo para mostrar: el user agent completo se sigue guardando aparte.
 */
final class Navegador
{
    public static function describir(?string $userAgent): ?string
    {
        if (! $userAgent) {
            return null;
        }

        $navegador = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/') || str_contains($userAgent, 'Opera') => 'Opera',
            str_contains($userAgent, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') || str_contains($userAgent, 'CriOS/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => null,
        };

        $sistema = match (true) {
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Mac OS X') || str_contains($userAgent, 'Macintosh') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        if (! $navegador && ! $sistema) {
            return 'Otro cliente';
        }

        return trim(($navegador ?? 'Navegador').($sistema ? " en {$sistema}" : ''));
    }
}
