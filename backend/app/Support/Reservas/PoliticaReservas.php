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

    /** @return array<string, int> */
    public static function valores(): array
    {
        return [
            self::TOLERANCIA => self::toleranciaMinutos(),
            self::RECORDATORIO => self::recordatorioMinutos(),
            self::VENTANA => self::ventanaReservadaMinutos(),
        ];
    }

    private static function leer(string $clave, string $claveConfig, int $porDefecto): int
    {
        $respaldo = (int) config("fragua_real.reservas.{$claveConfig}", $porDefecto);
        [$minimo, $maximo] = self::RANGOS[$clave];

        return max($minimo, min($maximo, (int) Configuracion::obtenerNumerico($clave, $respaldo)));
    }
}
