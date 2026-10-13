<?php

namespace App\Services;

use App\Models\Mesa;
use App\Models\Reserva;
use App\Support\Reservas\PoliticaReservas;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * M05 — Fase 4, tarea 3 (13/10/2026): lo que el sistema hace solo con las reservas. Lo ejecuta
 * el comando `reservas:procesar` cada minuto (routes/console.php). Usa los tiempos de
 * Configuración (PoliticaReservas, decisión D6: 15 min, 2 h y 1 h).
 *
 *  1. No-show automático (RF-M05-016, RN-M05-07/18): una reserva confirmada cuya hora más la
 *     tolerancia ya pasó sin que el cliente llegara se marca no-show; el depósito se retiene y la
 *     mesa vuelve a Libre (o queda Reservada si tiene otra reserva en la ventana).
 *  2. Mesa Reservada a tiempo (RF-M05-014, RN-M05-07): una mesa libre pasa a Reservada solo
 *     desde N minutos antes de la reserva; una Reservada sin reserva cercana vuelve a Libre.
 *  3. Recordatorio (RF-M05-024, RN-M05-13): N minutos antes de la hora se envía un correo al
 *     cliente, una sola vez. No se envía si la reserva se confirmó ya dentro de ese plazo (el
 *     cliente acaba de reservar; el aviso sobra).
 *
 * Cada paso es independiente: si uno falla con una reserva, se registra en el log y sigue.
 */
class ReservaAutomaticaService
{
    public function __construct(private readonly ReservaService $reservas)
    {
    }

    /** @return array{no_show: int, reservadas: int, liberadas: int, recordatorios: int} */
    public function procesar(): array
    {
        $resultado = ['no_show' => 0, 'reservadas' => 0, 'liberadas' => 0, 'recordatorios' => 0];

        $resultado['no_show'] = $this->noShowVencidas();
        [$resultado['reservadas'], $resultado['liberadas']] = $this->sincronizarMesas();
        $resultado['recordatorios'] = $this->enviarRecordatorios();

        return $resultado;
    }

    private function noShowVencidas(): int
    {
        $limite = now()->subMinutes(PoliticaReservas::toleranciaMinutos());
        $cantidad = 0;

        Reserva::where('estado', 'confirmada')
            ->where('fecha_hora', '<=', $limite)
            ->orderBy('fecha_hora')
            ->get() // no each(): marcar no-show o recordatorio saca filas de la consulta y el paginado se saltaría algunas
            ->each(function (Reserva $reserva) use (&$cantidad) {
                try {
                    $this->reservas->marcarNoShow($reserva, null, true);
                    $cantidad++;
                } catch (Throwable $e) {
                    Log::warning("No se pudo marcar no-show la reserva {$reserva->id}: {$e->getMessage()}");
                }
            });

        return $cantidad;
    }

    /** @return array{0: int, 1: int} mesas marcadas Reservadas y mesas devueltas a Libre */
    private function sincronizarMesas(): array
    {
        $reservadas = 0;
        $liberadas = 0;

        // Mesas que hoy están Reservadas más las que tienen una reserva confirmada cercana.
        $desde = now()->subMinutes(PoliticaReservas::toleranciaMinutos());
        $hasta = now()->addMinutes(PoliticaReservas::ventanaReservadaMinutos());
        $ids = Mesa::where('estado', 'reservada')->pluck('id')
            ->merge(Reserva::where('estado', 'confirmada')->whereBetween('fecha_hora', [$desde, $hasta])->pluck('mesa_id'))
            ->unique();

        foreach ($ids as $mesaId) {
            match ($this->reservas->sincronizarMesa($mesaId)) {
                'reservada' => $reservadas++,
                'libre' => $liberadas++,
                default => null,
            };
        }

        return [$reservadas, $liberadas];
    }

    private function enviarRecordatorios(): int
    {
        $anticipacion = PoliticaReservas::recordatorioMinutos();
        $cantidad = 0;

        Reserva::with('mesa')
            ->where('estado', 'confirmada')
            ->whereNull('recordatorio_enviado_at')
            ->where('fecha_hora', '>', now())
            ->where('fecha_hora', '<=', now()->addMinutes($anticipacion))
            ->orderBy('fecha_hora')
            ->get() // no each(): marcar no-show o recordatorio saca filas de la consulta y el paginado se saltaría algunas
            ->each(function (Reserva $reserva) use ($anticipacion, &$cantidad) {
                $momentoDelAviso = $reserva->fecha_hora->copy()->subMinutes($anticipacion);
                if ($reserva->deposito_pagado_at && $reserva->deposito_pagado_at->gte($momentoDelAviso)) {
                    return; // se confirmó ya dentro del plazo del recordatorio
                }
                if (! $reserva->cliente_correo) {
                    return;
                }

                try {
                    // Fase 4, tarea 4: el aviso se registra y se envía desde la cola (RF-M05-027).
                    app(AvisoReservaService::class)->programar($reserva, 'recordatorio');
                    $reserva->recordatorio_enviado_at = now();
                    $reserva->save();
                    $cantidad++;
                } catch (Throwable $e) {
                    Log::warning("No se pudo encolar el recordatorio de la reserva {$reserva->id}: {$e->getMessage()}");
                }
            });

        return $cantidad;
    }
}
