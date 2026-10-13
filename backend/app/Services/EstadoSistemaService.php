<?php

namespace App\Services;

use App\Jobs\LatidoCola;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4, tarea 1 (12/10/2026) — base de RF-M05-016/024 y de los avisos de la Fase 4.
 *
 * Las tareas automáticas necesitan dos procesos en el servidor que nadie ve: el programador
 * (cron con `php artisan schedule:run` cada minuto) y el trabajador de la cola
 * (`php artisan queue:work`, vigilado por Supervisor). Si alguno se cae, los no-show, los
 * recordatorios y los correos dejan de salir sin ningún error visible.
 *
 * Por eso cada minuto el programador deja un "latido" en la caché y encola un trabajo
 * (LatidoCola) que, al procesarse, deja otro. Con eso el Administrador ve en Configuración si
 * cada proceso está activo, cuándo fue su último latido y cuántos trabajos están pendientes o
 * fallaron.
 */
class EstadoSistemaService
{
    public const CLAVE_PROGRAMADOR = 'fragua:latido:programador';
    public const CLAVE_COLA = 'fragua:latido:cola';

    // Un proceso se considera activo si su último latido tiene a lo sumo estos minutos.
    public const MINUTOS_ACTIVO = 3;

    /** Lo llama el programador cada minuto (comando fragua:latido). */
    public function latido(): void
    {
        Cache::forever(self::CLAVE_PROGRAMADOR, now()->toIso8601String());
        LatidoCola::dispatch();
    }

    public function registrarLatidoCola(): void
    {
        Cache::forever(self::CLAVE_COLA, now()->toIso8601String());
    }

    public function estado(): array
    {
        $programador = $this->leer(self::CLAVE_PROGRAMADOR);
        $cola = $this->leer(self::CLAVE_COLA);
        $conexion = (string) config('queue.default');

        return [
            'programador' => [
                'ultimo_latido' => $programador?->toIso8601String(),
                'activo' => $this->activo($programador),
            ],
            'cola' => [
                'conexion' => $conexion,
                // Con "sync" los trabajos se hacen en la misma petición: no hace falta trabajador.
                'sincronica' => $conexion === 'sync',
                'ultimo_latido' => $cola?->toIso8601String(),
                'activa' => $conexion === 'sync' || $this->activo($cola),
                'pendientes' => $this->contar('jobs'),
                'fallidos' => $this->contar('failed_jobs'),
            ],
            'correo' => [
                'transporte' => (string) config('mail.default'),
                // "log" y "array" no envían correos de verdad (solo los registran).
                'envia_de_verdad' => ! in_array(config('mail.default'), ['log', 'array'], true),
            ],
            'minutos_activo' => self::MINUTOS_ACTIVO,
        ];
    }

    private function leer(string $clave): ?Carbon
    {
        $valor = Cache::get($clave);

        return is_string($valor) ? Carbon::parse($valor) : null;
    }

    private function activo(?Carbon $latido): bool
    {
        return $latido !== null && $latido->gte(now()->subMinutes(self::MINUTOS_ACTIVO));
    }

    private function contar(string $tabla): ?int
    {
        return Schema::hasTable($tabla) ? DB::table($tabla)->count() : null;
    }
}
