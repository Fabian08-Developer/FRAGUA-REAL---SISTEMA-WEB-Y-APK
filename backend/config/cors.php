<?php

/**
 * Endurecimiento (13/10/2026): quién puede llamar la API desde un navegador. Antes regían los valores
 * por defecto de Laravel (cualquier origen, "*"). Ahora solo el sitio propio (FRONTEND_URL) y los que
 * se agreguen en CORS_ORIGENES, separados por coma (por ejemplo la app móvil, si algún día se carga
 * desde otro origen). En producción el sitio y la API van en el mismo dominio (/api), así que el
 * navegador ni siquiera necesita CORS; esto cierra la puerta a otros sitios.
 *
 * La sesión viaja en el encabezado Authorization (token de Sanctum), no en cookies: no se envían
 * credenciales de navegador (supports_credentials = false).
 */
$origenes = array_values(array_filter(array_map('trim', array_merge(
    [rtrim((string) env('FRONTEND_URL', 'http://localhost:5173'), '/')],
    explode(',', (string) env('CORS_ORIGENES', '')),
))));

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => $origenes,
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Requested-With'],
    // Las exportaciones de reportes devuelven el id del archivo guardado y el nombre del archivo.
    'exposed_headers' => ['X-Exportacion-Id', 'Content-Disposition'],
    'max_age' => 3600,
    'supports_credentials' => false,
];
