<?php

namespace App\Services;

use App\Models\Mesa;
use App\Models\Pago;
use App\Models\Pedido;
use App\Models\Reserva;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Fase 2, tarea 2 (12/10/2026) — RF-M05-034, RN-M05-19: el depósito de una reserva se abona a los
 * pedidos de la visita cuando el cliente llega.
 *
 * Decisión D7 del plan: el abono es un PAGO de tipo "deposito" sobre el pedido, con el método con el
 * que se pagó el depósito. Así la cuenta de la mesa, el saldo, el cobro y la finalización funcionan
 * igual que con cualquier pago, y todo queda trazable. Ese pago no lleva turno de caja: el dinero ya
 * entró a la caja el día en que se recibió el depósito (o por la pasarela, si fue en línea), así que
 * no se cuenta dos veces.
 *
 * Reglas:
 *  - Se abona del pedido más antiguo al más nuevo de la visita, hasta cubrir el saldo de cada uno.
 *    Si la visita consume menos que el depósito, lo que sobra queda "por abonar" en la reserva y se
 *    usa en los pedidos nuevos de esa misma visita.
 *  - Si un pedido baja de valor (se edita o recibe un descuento) por debajo de lo abonado, el abono
 *    se reduce solo: el pago se reversa ("ajuste_abono") y se registra uno nuevo por lo que cabe; lo
 *    liberado pasa a otros pedidos de la visita con saldo. Lo mismo si el pedido se cancela.
 *  - El primer pedido en una mesa RESERVADA cuenta como la llegada del cliente de la reserva de hoy.
 */
class AbonoDepositoService
{
    public const ZONA_HORARIA_NEGOCIO = 'America/Bogota';

    /** Abona lo que queda del depósito a los pedidos de la visita con saldo. Devuelve lo abonado. */
    public function aplicar(Reserva $reserva, ?User $actor = null): float
    {
        return DB::transaction(function () use ($reserva, $actor) {
            $reserva = Reserva::whereKey($reserva->id)->lockForUpdate()->firstOrFail();
            $porAbonar = $reserva->depositoPorAbonar();
            $mesa = Mesa::find($reserva->mesa_id);
            if ($porAbonar <= 0 || $reserva->estado !== 'completada' || ! $mesa) {
                return 0.0;
            }

            $abonado = 0.0;
            foreach ($mesa->pedidosDeLaCuenta()->get() as $pedido) {
                if ($porAbonar <= 0) {
                    break;
                }
                if (! in_array($pedido->estado, ['pendiente', 'confirmado', 'preparando'], true)) {
                    continue;
                }
                $saldo = round((float) $pedido->total - $pedido->montoComprometido(), 2);
                $monto = round(min($saldo, $porAbonar), 2);
                if ($monto <= 0) {
                    continue;
                }

                $this->crearAbono($pedido, $reserva, $monto, $actor);
                $porAbonar = round($porAbonar - $monto, 2);
                $abonado = round($abonado + $monto, 2);
                $reserva->pedido_id ??= $pedido->id;
            }

            if ($abonado > 0) {
                $reserva->deposito_abonado = round((float) $reserva->deposito_abonado + $abonado, 2);
                $reserva->deposito_aplicado_abono = true;
                $reserva->save();

                app(AuditoriaService::class)->registrar('abonar_deposito_reserva', 'Reserva', $reserva->id, [
                    'monto' => $abonado,
                    'deposito' => (float) $reserva->deposito_valor,
                    'queda_por_abonar' => $reserva->depositoPorAbonar(),
                ], $actor);
            }

            return $abonado;
        });
    }

    /**
     * Al crear un pedido local: si la mesa estaba RESERVADA, ese pedido es la llegada del cliente
     * (se completa la reserva de hoy y se abona); si la reserva ya se había completado en esta
     * misma visita y le queda depósito, se abona al pedido nuevo.
     */
    public function alCrearPedidoLocal(Pedido $pedido, bool $mesaEstabaReservada, ?User $actor = null): void
    {
        if (! $pedido->esLocal() || ! $pedido->mesa_id) {
            return;
        }

        if ($mesaEstabaReservada) {
            $reserva = $this->reservaDeHoy($pedido->mesa_id);
            if ($reserva) {
                app(ReservaService::class)->completar($reserva, $actor);

                return; // completar() ya abona a los pedidos de la visita, incluido este
            }
        }

        $mesa = Mesa::find($pedido->mesa_id);
        if (! $mesa || ! $mesa->ocupada_desde) {
            return;
        }
        $reservas = Reserva::where('mesa_id', $mesa->id)
            ->where('estado', 'completada')
            ->where('deposito_pagado', true)
            ->where('deposito_reembolsado', false)
            ->where('llegada_at', '>=', $mesa->ocupada_desde)
            ->get();
        foreach ($reservas as $reserva) {
            if ($reserva->depositoPorAbonar() > 0) {
                $this->aplicar($reserva, $actor);
            }
        }
    }

    /** La reserva confirmada de esa mesa para hoy, la más cercana a esta hora. */
    public function reservaDeHoy(int $mesaId): ?Reserva
    {
        $ahora = Carbon::now(self::ZONA_HORARIA_NEGOCIO);

        return Reserva::where('mesa_id', $mesaId)
            ->where('estado', 'confirmada')
            ->whereBetween('fecha_hora', [$ahora->copy()->startOfDay()->utc(), $ahora->copy()->endOfDay()->utc()])
            ->get()
            ->sortBy(fn (Reserva $r) => abs($r->fecha_hora->diffInSeconds($ahora)))
            ->first();
    }

    /**
     * Si el pedido quedó por debajo de lo que ya tiene comprometido y parte es abono del depósito,
     * el abono se reduce (sin tocar los pagos de caja). Lo liberado se abona a otros pedidos de la
     * visita con saldo. Llamar dentro de la transacción que cambió el total.
     */
    public function reajustar(Pedido $pedido, ?User $actor = null): void
    {
        $exceso = round($pedido->montoComprometido() - (float) $pedido->total, 2);
        if ($exceso <= 0) {
            return;
        }

        $this->reducirAbonos($pedido, $exceso, $actor, 'El pedido bajó de valor; el abono del depósito se ajusta a lo que cabe.');
    }

    /** Un pedido cancelado devuelve su abono a la reserva, para los demás pedidos de la visita. */
    public function liberarDePedidoCancelado(Pedido $pedido, ?User $actor = null): void
    {
        $this->reducirAbonos($pedido, PHP_FLOAT_MAX, $actor, 'Pedido cancelado; el abono del depósito vuelve a la reserva.');
    }

    /** Un Administrador anuló un abono: ese valor vuelve a quedar por abonar en la reserva. */
    public function devolverAReserva(Pago $pago): void
    {
        if (! $pago->esAbonoDeDeposito() || ! $pago->reserva_id) {
            return;
        }
        $reserva = Reserva::whereKey($pago->reserva_id)->lockForUpdate()->first();
        if ($reserva) {
            $reserva->deposito_abonado = max(0, round((float) $reserva->deposito_abonado - (float) $pago->monto, 2));
            $reserva->save();
        }
    }

    private function reducirAbonos(Pedido $pedido, float $cuanto, ?User $actor, string $motivo): void
    {
        $abonos = $pedido->pagos()->where('tipo', 'deposito')->where('anulado', false)->orderByDesc('id')->get();
        $reservasTocadas = [];

        foreach ($abonos as $abono) {
            if ($cuanto <= 0) {
                break;
            }
            $quitar = round(min((float) $abono->monto, $cuanto), 2);
            $queda = round((float) $abono->monto - $quitar, 2);

            $abono->anulado = true;
            $abono->reverso_tipo = 'ajuste_abono';
            $abono->anulado_motivo = $motivo;
            $abono->anulado_por = $actor?->id;
            $abono->anulado_at = now();
            $abono->save();

            $reserva = Reserva::whereKey($abono->reserva_id)->lockForUpdate()->first();
            if ($queda > 0 && $reserva) {
                $this->crearAbono($pedido, $reserva, $queda, $actor);
            }
            if ($reserva) {
                $reserva->deposito_abonado = max(0, round((float) $reserva->deposito_abonado - $quitar, 2));
                $reserva->save();
                $reservasTocadas[$reserva->id] = $reserva;

                app(AuditoriaService::class)->registrar('ajustar_abono_deposito', 'Reserva', $reserva->id, [
                    'pedido_id' => $pedido->id,
                    'abono_anterior' => (float) $abono->monto,
                    'abono_nuevo' => $queda,
                    'motivo' => $motivo,
                ], $actor);
            }
            $cuanto = round($cuanto - $quitar, 2);
        }

        // Lo liberado pasa a otros pedidos de la visita que tengan saldo.
        foreach ($reservasTocadas as $reserva) {
            $this->aplicar($reserva, $actor);
        }
    }

    private function crearAbono(Pedido $pedido, Reserva $reserva, float $monto, ?User $actor): Pago
    {
        return app(PagoService::class)->registrarAbonoDeposito($pedido, $reserva, $monto, $actor);
    }
}
