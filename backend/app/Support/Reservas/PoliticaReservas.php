<?php

namespace App\Support\Reservas;

use App\Models\Configuracion;

/**
 * M05 — Fase 4, tarea 2 (12/10/2026): los tiempos de las reservas que el Administrador ajusta
 * en Configuración (decisión D6 del plan, valores iniciales del 12/10/2026):
 *  - tolerancia: minutos de espera después de la hora antes de liberar la reserva como no-show
 *    (RF-M05-016/017, RN-M05-07/08). Inicial: 15 min.
 *  - recordatorio: con cuánta anticipación se avisa al cliente (RF-M05-024/026, RN-M05-13).
 *    Inicial: 2 h.
 *  - ventana: desde cuántos minutos antes de la hora la mesa se ve "Reservada" (RF-M05-014,
 *    RN-M05-07). Inicial: 1 h.
 *
 * Se leen de la tabla `configuraciones`; si la fila aún no existe, de config/fragua_real.php.
 * Los rangos permitidos evitan valores que dejarían el sistema sin sentido (p. ej. tolerancia 0).
 */
final class PoliticaReservas
{
    public const TOLERANCIA = 'reserva_tolerancia_minutos';
    public const RECORDATORIO = 'reserva_recordatorio_minutos';
    public const VENTANA = 'reserva_ventana_reservada_minutos';

    /** clave => [mínimo, máximo] en minutos */
    public const RANGOS = [
        self::TOLERANCIA => [5, 120],
        self::RECORDATORIO => [30, 2880],
        self::VENTANA => [15, 240],
    ];

    public static function toleranciaMinutos(): int
    {
        return self::leer(self::TOLERANCIA, 'tolerancia_minutos', 15);
    }

    public static function recordatorioMinutos(): int
    {
        return self::leer(self::RECORDATORIO, 'recordatorio_minutos', 120);
    }

    public static function ventanaReservadaMinutos(): int
    {
        return self::leer(self::VENTANA, 'ventana_reservada_minutos', 60);
    }

    /** @return array<string, int|string> */
    public static function valores(): array
    {
        return [
            self::TOLERANCIA => self::toleranciaMinutos(),
            self::RECORDATORIO => self::recordatorioMinutos(),
            self::VENTANA => self::ventanaReservadaMinutos(),
            // Corrección (19/10/2026): horario y anticipación, para que Reservar ofrezca solo horas válidas.
            'reserva_hora_inicio' => self::horaInicio(),
            'reserva_hora_fin' => self::horaFin(),
            'reserva_anticipacion_minutos' => self::anticipacionMinutos(),
        ];
    }

    /** Primera hora del día a la que se puede reservar ("HH:MM", hora de Colombia). */
    public static function horaInicio(): string
    {
        return (string) config('fragua_real.reservas.hora_inicio', '16:00');
    }

    /** Última hora del día a la que se puede reservar ("HH:MM", hora de Colombia, incluida). */
    public static function horaFin(): string
    {
        return (string) config('fragua_real.reservas.hora_fin', '23:00');
    }

    /** Con cuántos minutos de anticipación, como mínimo, se puede hacer una reserva. */
    public static function anticipacionMinutos(): int
    {
        return max(0, (int) config('fragua_real.reservas.anticipacion_minutos', 180));
    }

    /**
     * Por qué no se puede reservar a esa hora, o null si sí se puede (horario del negocio y
     * anticipación mínima). Recibe el instante en cualquier zona; se mira en hora de Colombia.
     */
    public static function motivoHoraNoPermitida(\Carbon\CarbonInterface $fechaHora, ?\Carbon\CarbonInterface $ahora = null): ?string
    {
        $local = $fechaHora->copy()->setTimezone(self::ZONA);
        $hora = $local->format('H:i');
        if ($hora < self::horaInicio() || $hora > self::horaFin()) {
            return 'Las reservas son de '.self::horaLegible(self::horaInicio()).' a '.self::horaLegible(self::horaFin()).'.';
        }
        $minimo = ($ahora ?? now())->copy()->addMinutes(self::anticipacionMinutos());
        if ($fechaHora->lt($minimo)) {
            $horas = intdiv(self::anticipacionMinutos(), 60);
            $texto = self::anticipacionMinutos() % 60 === 0 ? ($horas === 1 ? '1 hora' : "{$horas} horas") : self::anticipacionMinutos().' minutos';

            return "La reserva se hace con al menos {$texto} de anticipación.";
        }

        return null;
    }

    private const ZONA = 'America/Bogota';

    private static function horaLegible(string $hhmm): string
    {
        return \Carbon\Carbon::createFromFormat('H:i', $hhmm, self::ZONA)->format('g:i').' '
            .(((int) substr($hhmm, 0, 2)) < 12 ? 'a. m.' : 'p. m.');
    }

    private static function leer(string $clave, string $claveConfig, int $porDefecto): int
    {
        $respaldo = (int) config("fragua_real.reservas.{$claveConfig}", $porDefecto);
        [$minimo, $maximo] = self::RANGOS[$clave];

        return max($minimo, min($maximo, (int) Configuracion::obtenerNumerico($clave, $respaldo)));
    }
}
