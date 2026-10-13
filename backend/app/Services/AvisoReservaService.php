<?php

namespace App\Services;

use App\Jobs\EnviarAvisoReserva;
use App\Models\Reserva;
use App\Models\ReservaAviso;
use App\Notifications\CancelacionReservaNotification;
use App\Notifications\ConfirmacionReservaNotification;
use App\Notifications\NoShowReservaNotification;
use App\Notifications\RecordatorioReservaNotification;
use Illuminate\Notifications\Notification as Aviso;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Throwable;

/**
 * M05 — Fase 4, tarea 4 (13/10/2026): avisos al cliente de una reserva por correo (decisión D3:
 * correo en V1, SMS después). RF-M05-023 (confirmación), 024 (recordatorio), 025 (cancelación y
 * no-show) y 027 (registro de cada envío o falla).
 *
 * programar() deja el aviso "pendiente" en reserva_avisos y encola EnviarAvisoReserva cuando la
 * transacción termina; el trabajo llama a entregar(), que envía el correo y marca el aviso como
 * enviado, o lo deja fallido con el error (después de los reintentos). Cada resultado queda
 * también en la auditoría. Si el correo no sale, la operación de la reserva no se afecta.
 */
class AvisoReservaService
{
    public function programar(Reserva $reserva, string $tipo): ?ReservaAviso
    {
        if (! in_array($tipo, ReservaAviso::TIPOS, true)) {
            throw new InvalidArgumentException("Tipo de aviso desconocido: {$tipo}");
        }
        if (! $reserva->cliente_correo) {
            return null;
        }

        $aviso = ReservaAviso::create([
            'reserva_id' => $reserva->id,
            'tipo' => $tipo,
            'canal' => 'correo',
            'destino' => $reserva->cliente_correo,
            'estado' => 'pendiente',
        ]);

        EnviarAvisoReserva::dispatch($aviso->id)->afterCommit();

        return $aviso;
    }

    /** Envía el correo ahora. Lanza la excepción si el envío falla (el trabajo decide si reintenta). */
    public function entregar(ReservaAviso $aviso): void
    {
        $reserva = $aviso->reserva()->with('mesa')->firstOrFail();

        Notification::route('mail', [$aviso->destino => $reserva->cliente_nombre])
            ->notifyNow($this->notificacion($aviso->tipo, $reserva));
    }

    public function marcarEnviado(ReservaAviso $aviso): void
    {
        $aviso->forceFill(['estado' => 'enviado', 'enviado_at' => now(), 'error' => null])->save();

        app(AuditoriaService::class)->registrar('enviar_aviso_reserva', 'Reserva', $aviso->reserva_id, [
            'aviso' => $aviso->tipo, 'canal' => $aviso->canal, 'destino' => $aviso->destino, 'intentos' => $aviso->intentos,
        ]);
    }

    public function marcarFallido(ReservaAviso $aviso, Throwable $error): void
    {
        $aviso->forceFill(['estado' => 'fallido', 'error' => mb_substr($error->getMessage(), 0, 1000)])->save();

        app(AuditoriaService::class)->registrar('falla_aviso_reserva', 'Reserva', $aviso->reserva_id, [
            'aviso' => $aviso->tipo, 'canal' => $aviso->canal, 'destino' => $aviso->destino,
            'intentos' => $aviso->intentos, 'error' => mb_substr($error->getMessage(), 0, 300),
        ]);
    }

    /** Vuelve a intentar un aviso que falló (botón "Reintentar" en Reservas). */
    public function reintentar(ReservaAviso $aviso): ReservaAviso
    {
        if ($aviso->estado !== 'fallido') {
            throw new \RuntimeException('Solo se reintenta un aviso que falló.');
        }
        $aviso->forceFill(['estado' => 'pendiente', 'error' => null])->save();
        EnviarAvisoReserva::dispatch($aviso->id)->afterCommit();

        return $aviso;
    }

    private function notificacion(string $tipo, Reserva $reserva): Aviso
    {
        return match ($tipo) {
            'confirmacion' => new ConfirmacionReservaNotification($reserva),
            'recordatorio' => new RecordatorioReservaNotification($reserva),
            'cancelacion' => new CancelacionReservaNotification($reserva),
            'no_show' => new NoShowReservaNotification($reserva),
        };
    }
}
