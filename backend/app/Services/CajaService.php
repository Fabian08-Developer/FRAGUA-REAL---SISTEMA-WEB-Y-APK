<?php

namespace App\Services;

use App\Models\CajaTurno;
use App\Models\CompraGasto;
use App\Models\Reserva;
use App\Models\User;
use RuntimeException;

/**
 * M06 — Caja y Ventas. RN-M06-03: un solo turno de caja por día
 * (reforzado además a nivel de base de datos con `caja_turnos.fecha` único).
 */
class CajaService
{
    // BUG REAL encontrado en vivo al probar esta vista: config/app.php tiene
    // 'timezone' => 'UTC' (toda la app corre en UTC), pero el restaurante opera en hora
    // de Colombia (UTC-5). Si "hoy" se calculara con now() crudo, el "día" de caja
    // cambiaría a las 7pm hora Colombia (medianoche UTC) en vez de a medianoche local —
    // es decir, el turno de la noche se marcaría como "de mañana" y RN-M06-03 bloquearía
    // abrir uno nuevo aunque el día de negocio no haya terminado. Por eso "hoy" para
    // efectos de caja se calcula explícitamente en esta zona horaria, sin tocar el
    // 'timezone' global de la app (eso afectaría casts/logs/etc. de todo el sistema).
    private const ZONA_HORARIA_NEGOCIO = 'America/Bogota';

    public function turnoAbierto(?\DateTimeInterface $fecha = null): ?CajaTurno
    {
        $fecha ??= now(self::ZONA_HORARIA_NEGOCIO);

        return CajaTurno::whereDate('fecha', $fecha)->where('estado', 'abierta')->first();
    }

    public function abrir(User $actor, float $baseInicial, ?\DateTimeInterface $fecha = null): CajaTurno
    {
        $fecha ??= now(self::ZONA_HORARIA_NEGOCIO);

        if (CajaTurno::whereDate('fecha', $fecha)->exists()) {
            throw new RuntimeException('Ya existe un turno de caja para hoy (RN-M06-03).');
        }

        $turno = new CajaTurno();
        $turno->fecha = $fecha;
        $turno->base_inicial = $baseInicial;
        $turno->efectivo_esperado = $baseInicial;
        $turno->abierto_por = $actor->id;
        $turno->abierto_at = now();
        $turno->estado = 'abierta';
        $turno->save();

        // RF-M06-020: apertura auditada.
        app(AuditoriaService::class)->registrar('abrir_caja', 'CajaTurno', $turno->id, ['base_inicial' => $baseInicial], $actor);

        return $turno;
    }

    /**
     * Recalcula el efectivo esperado = base inicial + pagos en efectivo, aprobados y
     * no anulados, registrados en este turno.
     */
    /**
     * RN-M06-08: efectivo esperado = base inicial + pagos en efectivo del día − compras y
     * gastos en efectivo del día (M03) − anulaciones de pagos en efectivo. Las anulaciones
     * restan porque un pago anulado ya no se suma.
     *
     * CORREGIDO (Fase 0, tarea 3, 09/10/2026): antes no restaba las compras ni los gastos en
     * efectivo de M03 (RF-M06-013), así que el arqueo mostraba un descuadre falso cada vez
     * que se pagaba algo de la caja.
     */
    public function recalcularEfectivoEsperado(CajaTurno $turno): float
    {
        $totalEfectivo = $turno->pagos()
            ->where('metodo', 'efectivo')
            ->where('estado', 'aprobado')
            ->where('anulado', false)
            ->sum('monto');

        // Fase 2, tarea 2: los abonos de depósito (pagos tipo "deposito") no llevan turno, así que
        // no entran aquí; lo que sí entra es el depósito en efectivo recibido en este turno, y sale
        // el que se devolvió en este turno.
        $esperado = (float) $turno->base_inicial + (float) $totalEfectivo - $this->gastosEnEfectivo($turno)
            + $this->depositosEnEfectivo($turno) - $this->reembolsosDeDepositoEnEfectivo($turno);
        $turno->efectivo_esperado = $esperado;
        $turno->save();

        return $esperado;
    }

    // Fase 2, tarea 2 (RN-M05-16, RN-M06-08): depósitos de reserva recibidos en efectivo en este turno.
    public function depositosEnEfectivo(CajaTurno $turno): float
    {
        return (float) Reserva::where('deposito_caja_turno_id', $turno->id)
            ->where('deposito_metodo', 'efectivo')
            ->where('deposito_pagado', true)
            ->sum('deposito_valor');
    }

    // Fase 2, tarea 2 (RN-M05-17): depósitos en efectivo devueltos en este turno.
    public function reembolsosDeDepositoEnEfectivo(CajaTurno $turno): float
    {
        return (float) Reserva::where('deposito_reembolso_caja_turno_id', $turno->id)
            ->where('deposito_metodo', 'efectivo')
            ->where('deposito_reembolsado', true)
            ->sum('deposito_valor');
    }

    // RF-M06-013: compras y gastos activos pagados en efectivo con la fecha del turno.
    public function gastosEnEfectivo(CajaTurno $turno): float
    {
        return (float) CompraGasto::where('metodo_pago', 'efectivo')
            ->where('estado', 'activo')
            ->whereDate('fecha', $turno->fecha)
            ->sum('valor');
    }

    // Turno (abierto o cerrado) del día de negocio. Recibe una fecha 'Y-m-d' (ya es un día
    // de negocio, como compras_gastos.fecha) o un instante (como el created_at de un pago,
    // en UTC), que se pasa a la hora de Bogotá antes de tomar el día.
    public function turnoDeFecha(\DateTimeInterface|string $fecha): ?CajaTurno
    {
        $dia = is_string($fecha) ? $fecha : \Illuminate\Support\Carbon::instance($fecha)->setTimezone(self::ZONA_HORARIA_NEGOCIO)->toDateString();

        return CajaTurno::whereDate('fecha', $dia)->first();
    }

    // Lo llama M03 al registrar, editar o anular una compra o gasto: si la caja de ese día
    // sigue abierta, su efectivo esperado se actualiza. Una caja cerrada no se toca.
    public function recalcularSiEstaAbierto(\DateTimeInterface|string $fecha): void
    {
        $turno = $this->turnoDeFecha($fecha);
        if ($turno && $turno->estaAbierta()) {
            $this->recalcularEfectivoEsperado($turno);
        }
    }

    // Inicio y fin (en UTC) del día de negocio de un turno, para buscar lo que no tiene
    // caja_turno_id, como los pagos en línea.
    public function rangoDelTurno(CajaTurno $turno): array
    {
        $inicio = \Illuminate\Support\Carbon::parse($turno->fecha->toDateString(), self::ZONA_HORARIA_NEGOCIO)->startOfDay();

        return [$inicio->copy()->utc(), $inicio->copy()->endOfDay()->utc()];
    }

    public function cerrar(CajaTurno $turno, User $actor, float $efectivoContado, ?string $justificacion = null): CajaTurno
    {
        if (! $turno->estaAbierta()) {
            throw new RuntimeException('Este turno ya está cerrado.');
        }

        $esperado = $this->recalcularEfectivoEsperado($turno);
        $diferencia = round($efectivoContado - $esperado, 2);

        if ($diferencia !== 0.0 && empty($justificacion)) {
            throw new RuntimeException('Hay un descuadre de caja: se requiere justificación para cerrar (RF-M06-015/016).');
        }

        $turno->efectivo_contado = $efectivoContado;
        $turno->diferencia = $diferencia;
        $turno->justificacion_descuadre = $diferencia !== 0.0 ? $justificacion : null;
        $turno->cerrado_por = $actor->id;
        $turno->cerrado_at = now();
        $turno->estado = 'cerrada';
        $turno->save();

        // RF-M06-020: cierre auditado, con el descuadre si lo hubo.
        app(AuditoriaService::class)->registrar('cerrar_caja', 'CajaTurno', $turno->id, [
            'efectivo_esperado' => $esperado,
            'efectivo_contado' => $efectivoContado,
            'diferencia' => $diferencia,
            'justificacion' => $turno->justificacion_descuadre,
        ], $actor);

        return $turno;
    }
}
