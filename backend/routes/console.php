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
