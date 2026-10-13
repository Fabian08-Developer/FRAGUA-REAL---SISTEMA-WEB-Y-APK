<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Fase 3, tarea 4 (RF-M01-010, RNF-M01-005): borra de la base las sesiones vencidas o que
// llevan más del tiempo de inactividad sin usarse (config/fragua_real.php, "sesiones"). Ya no
// sirven para entrar (Sanctum las rechaza); esto solo evita que la tabla crezca sin fin.
// Corre a diario con el programador de Laravel (`php artisan schedule:run` cada minuto en el
// cron del VPS; se pone en marcha en la Fase 4). También se puede correr a mano.
Artisan::command('sesiones:limpiar', function (\App\Services\SesionService $sesiones) {
    $borradas = $sesiones->limpiarVencidas();
    $this->info("Sesiones vencidas borradas: {$borradas}");
})->purpose('Borra las sesiones vencidas o inactivas');

\Illuminate\Support\Facades\Schedule::command('sesiones:limpiar')->dailyAt('03:30')->timezone('America/Bogota');

// Fase 4, tarea 1: latido del programador y de la cola, cada minuto. Si el cron o el
// trabajador de la cola no están corriendo en el servidor, el Administrador lo ve en
// Configuración > Tareas automáticas (EstadoSistemaService).
Artisan::command('fragua:latido', function (\App\Services\EstadoSistemaService $estado) {
    $estado->latido();
    $this->info('Latido registrado.');
})->purpose('Registra que el programador corre y encola una prueba para la cola');

\Illuminate\Support\Facades\Schedule::command('fragua:latido')->everyMinute();

// Fase 4, tarea 3 (RF-M05-014, 016, 024, RN-M05-07): no-show automático al vencer la tolerancia,
// mesa Reservada solo dentro de la ventana previa y recordatorio por correo antes de la hora.
// Usa los tiempos de Configuración (15 min, 2 h y 1 h al empezar).
Artisan::command('reservas:procesar', function (\App\Services\ReservaAutomaticaService $servicio) {
    $r = $servicio->procesar();
    $this->info("No-show: {$r['no_show']} · mesas reservadas: {$r['reservadas']} · mesas liberadas: {$r['liberadas']} · recordatorios: {$r['recordatorios']}");
})->purpose('Aplica el no-show automático, la ventana de mesa Reservada y los recordatorios');

\Illuminate\Support\Facades\Schedule::command('reservas:procesar')->everyMinute()->withoutOverlapping(5);

// Fase 6, tarea 1 (RF-M06-006, D4): por si un evento de la pasarela se pierde, cada 5 minutos se
// pregunta por los pagos en línea que siguen pendientes y se cierran los que vencieron sin pagarse.
// No hace nada mientras la pasarela esté en modo manual.
Artisan::command('pasarela:conciliar', function (\App\Services\PasarelaPagoService $pasarela) {
    $r = $pasarela->conciliar();
    $this->info("Revisadas: {$r['revisadas']} · cerradas: {$r['cerradas']} · abandonadas: {$r['abandonadas']} · sin respuesta: {$r['sin_respuesta']}");
})->purpose('Concilia con la pasarela los pagos en línea pendientes');

\Illuminate\Support\Facades\Schedule::command('pasarela:conciliar')->everyFiveMinutes()->withoutOverlapping(10);

// Endurecimiento (13/10/2026): revisión antes de abrir al público (APP_DEBUG, HTTPS, cuentas de prueba,
// correo, cola, pasarela, CORS). Sale con código 1 si hay algo grave. Correrlo en el VPS después de
// cada despliegue; lo mismo se ve en Administrador > Configuración.
Artisan::command('fragua:revisar-produccion', function (\App\Services\RevisionProduccionService $revision) {
    $items = $revision->revisar();
    $marcas = ['ok' => '[OK]   ', 'aviso' => '[AVISO]', 'error' => '[ERROR]'];
    foreach ($items as $i) {
        $linea = "{$marcas[$i['nivel']]} {$i['titulo']}: {$i['detalle']}";
        match ($i['nivel']) {
            'error' => $this->error($linea),
            'aviso' => $this->warn($linea),
            default => $this->line($linea),
        };
    }
    if ($revision->hayErrores($items)) {
        $this->error('Hay puntos por corregir antes de abrir al público.');

        return 1;
    }
    $this->info('Listo para producción (revisa los avisos, si hay).');

    return 0;
})->purpose('Revisa la configuración de seguridad antes de abrir al público');
