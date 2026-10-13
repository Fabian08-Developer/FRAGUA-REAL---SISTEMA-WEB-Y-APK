<?php

namespace App\Support\Reportes;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * M08 — resuelve el periodo que el Administrador elige (RF-M08-001: semana actual, mes
 * actual, o rango personalizado; RN-M08-03: semana actual por defecto), calcula el periodo
 * equivalente inmediatamente anterior para comparar (RF-M08-010, RN-M08-04), y marca si el
 * periodo sigue en curso (RN-M08-05).
 *
 * CORREGIDO (01/10/2026): igual que CajaService/StoreReservaRequest, la app corre en
 * 'UTC' (config('app.timezone')) pero el restaurante opera en hora de Colombia. Antes
 * "hoy"/"esta semana"/"este mes" se calculaba con CarbonImmutable::now() en UTC, así que
 * entre las 7:00 p. m. y la medianoche hora Bogotá (cuando UTC ya cambió de día) "semana
 * actual"/"mes actual" podían excluir el día de hoy real, y un periodo que en Bogotá
 * seguía en curso se marcaba como ya cerrado (RN-M08-05 mal evaluada). $desde/$hasta se
 * calculan aquí en hora de Colombia y se exponen ya convertidos a UTC (->utc()) porque
 * ReporteService los usa tal cual contra columnas timestamp en UTC (finalizado_at,
 * created_at de Pago, fecha_hora de Reserva); $desdeFecha/$hastaFecha exponen la fecha de
 * calendario en Bogotá (sin convertir) para las columnas DATE (CompraGasto.fecha,
 * CierreDiario.fecha), que no tienen hora y por eso no deben pasar por ->utc() — un
 * ->utc() ahí correría la fecha un día en los extremos del rango.
 */
class PeriodoReporte
{
    private const ZONA_HORARIA_NEGOCIO = 'America/Bogota';

    public function __construct(
        public readonly CarbonImmutable $desde,
        public readonly CarbonImmutable $hasta,
        public readonly bool $parcial,
        public readonly string $desdeFecha,
        public readonly string $hastaFecha,
    ) {
    }

    public static function resolver(string $periodo, ?string $desde, ?string $hasta): self
    {
        $hoyNegocio = CarbonImmutable::now(self::ZONA_HORARIA_NEGOCIO)->startOfDay();

        [$inicioNegocio, $finNegocio] = match ($periodo) {
            'semana' => [$hoyNegocio->startOfWeek(), $hoyNegocio->endOfWeek()],
            'mes' => [$hoyNegocio->startOfMonth(), $hoyNegocio->endOfMonth()],
            'personalizado' => [
                CarbonImmutable::parse($desde, self::ZONA_HORARIA_NEGOCIO)->startOfDay(),
                CarbonImmutable::parse($hasta, self::ZONA_HORARIA_NEGOCIO)->endOfDay(),
            ],
            default => throw new InvalidArgumentException("Periodo inválido: {$periodo}."),
        };

        // RN-M08-05: un periodo que incluye hoy (en Bogotá) todavía no ha terminado.
        $parcial = $hoyNegocio->between($inicioNegocio, $finNegocio);

        return new self(
            $inicioNegocio->utc(),
            $finNegocio->utc(),
            $parcial,
            $inicioNegocio->toDateString(),
            $finNegocio->toDateString(),
        );
    }

    // RN-M08-04: el periodo equivalente inmediatamente anterior (misma duración, justo antes).
    // Se recalcula desde las fechas de calendario en Bogotá (desdeFecha/hastaFecha), no desde
    // los instantes UTC ya convertidos — restar un día a un instante UTC cerca de la
    // medianoche Bogotá puede aterrizar en el día de calendario equivocado.
    public function anterior(): self
    {
        $duracionDias = CarbonImmutable::parse($this->desdeFecha)
            ->diffInDays(CarbonImmutable::parse($this->hastaFecha));

        $hastaAnteriorFecha = CarbonImmutable::parse($this->desdeFecha)->subDay();
        $desdeAnteriorFecha = $hastaAnteriorFecha->subDays($duracionDias);

        $inicioNegocio = CarbonImmutable::parse($desdeAnteriorFecha->toDateString(), self::ZONA_HORARIA_NEGOCIO)->startOfDay();
        $finNegocio = CarbonImmutable::parse($hastaAnteriorFecha->toDateString(), self::ZONA_HORARIA_NEGOCIO)->endOfDay();

        return new self(
            $inicioNegocio->utc(),
            $finNegocio->utc(),
            false,
            $inicioNegocio->toDateString(),
            $finNegocio->toDateString(),
        );
    }

    public function aArray(): array
    {
        return [
            'desde' => $this->desdeFecha,
            'hasta' => $this->hastaFecha,
            'parcial' => $this->parcial,
        ];
    }
}
