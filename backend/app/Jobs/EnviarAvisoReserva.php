<?php

namespace App\Jobs;

use App\Models\ReservaAviso;
use App\Services\AvisoReservaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Fase 4, tarea 4 (RF-M05-027): envía un aviso de reserva desde la cola. Hasta 3 intentos, con un
 * minuto entre uno y otro; si el último falla, el aviso queda "fallido" con el error y se puede
 * reintentar desde Reservas. Nunca deja escapar la excepción: un correo que no sale no debe
 * romper la acción que lo generó (con la cola "sync" correría dentro de esa misma petición).
 */
class EnviarAvisoReserva implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const INTENTOS = 3;
    public const ESPERA_SEGUNDOS = 60;

    public int $tries = self::INTENTOS;

    public function __construct(public readonly int $avisoId)
    {
    }

    public function handle(AvisoReservaService $avisos): void
    {
        $aviso = ReservaAviso::find($this->avisoId);
        if (! $aviso || $aviso->estado === 'enviado') {
            return;
        }

        $aviso->increment('intentos');

        try {
            $avisos->entregar($aviso);
            $avisos->marcarEnviado($aviso->fresh());
        } catch (Throwable $error) {
            $quedanIntentos = $this->job
                && $this->job->getConnectionName() !== 'sync'
                && $this->attempts() < self::INTENTOS;
            if ($quedanIntentos) {
                $aviso->forceFill(['error' => mb_substr($error->getMessage(), 0, 1000)])->save();
                $this->release(self::ESPERA_SEGUNDOS);

                return;
            }
            $avisos->marcarFallido($aviso->fresh(), $error);
        }
    }
}
