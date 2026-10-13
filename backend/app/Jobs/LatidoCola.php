<?php

namespace App\Jobs;

use App\Services\EstadoSistemaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Fase 4, tarea 1: trabajo mínimo que el programador encola cada minuto. Si el proceso de la
 * cola (queue:work) está corriendo, lo procesa y deja la hora en la caché; así el
 * Administrador ve en Configuración si los avisos en cola se están enviando.
 */
class LatidoCola implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function handle(EstadoSistemaService $estado): void
    {
        $estado->registrarLatidoCola();
    }
}
