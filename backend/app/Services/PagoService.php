<?php

namespace App\Services;

use App\Models\AjusteCaja;
use App\Models\Pago;
use App\Models\Pedido;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * M06 (Caja y Ventas): regla de cobro RF-M06-003, RF-M06-004, RNF-M06-004.
 * Responsabilidad (documento de arquitectura, sección 4): registrar pagos —incluido pago
 * mixto—, decidir quién puede cobrar, y anular pagos.
 *
 * La autorización HTTP vive en PagoPolicy; este Service la vuelve a aplicar como
 * defensa en profundidad para que también sea válida si algo la invoca fuera de un
 * request HTTP (consola, colas).
 */
class PagoService
{
    public function __construct(
        private readonly PedidoService $pedidoService,
        private readonly CajaService $cajaService,
    ) {
    }

    /**
     * Registra un cobro presencial (efectivo/tarjeta/transferencia) hecho por el Mesero.
     * $actor es quien cobra: siempre el Mesero, sin importar el canal (local, domicilio
     * contra entrega o recogida). El Administrador nunca cobra (RNF-M06-004).
     */
    public function registrarCobro(Pedido $pedido, User $actor, array $data): Pago
    {
        $this->validarQuienCobra($pedido, $actor);

        $metodo = $data['metodo'];
        if ($metodo === 'en_linea') {
            throw new RuntimeException('Un cobro presencial no puede usar el método en_linea; usa registrarPagoEnLinea().');
        }

        // RF-M06-004, RN-M06-06, CA-M06-003: el pago contra entrega de un domicilio se
        // registra después de confirmar la entrega.
        if ($pedido->esDomicilio() && $pedido->entregado_at === null) {
            throw new RuntimeException('Primero confirma la entrega del domicilio; el pago contra entrega se registra después (RF-M06-004).');
        }

        $turno = $this->cajaService->turnoAbierto();
        if (! $turno) {
            throw new RuntimeException('No hay un turno de caja abierto para registrar el cobro (RN-M06-03).');
        }

        return $this->crearPago($pedido, [
            ...$data,
            'metodo' => $metodo,
            'caja_turno_id' => $turno->id,
            'estado' => 'aprobado',
            'registrado_por' => $actor->id,
        ]);
    }

    /**
     * Pago en línea (Nequi/Bancolombia): autoservicio del Cliente, sin empleado de por medio.
     * `registrado_por` queda en NULL (ADR-004). Sin una pasarela real integrada todavía,
     * el estado queda 'pendiente' hasta que algo confirme el pago (webhook u operación manual).
     */
    public function registrarPagoEnLinea(Pedido $pedido, array $data): Pago
    {
        // CORREGIDO (Fase 0, tarea 4, 09/10/2026): RF-M06-005/008. El pago en línea es para
        // domicilio y recogida mientras el pedido está pendiente, y solo puede haber un
        // intento en curso a la vez: antes se podían acumular varios pagos pendientes del
        // mismo pedido (por ejemplo, al reintentar desde el Checkout).
        if (! $pedido->esDomicilio() && ! $pedido->esRecogida()) {
            throw new RuntimeException('El pago en línea es solo para domicilios y recogidas (RN-M07-07).');
        }
        if ($pedido->estado !== 'pendiente') {
            throw new RuntimeException('Este pedido ya no está pendiente; no admite un pago en línea nuevo.');
        }
        $hayPendiente = $pedido->pagos()
            ->where('metodo', 'en_linea')
            ->where('estado', 'pendiente')
            ->where('anulado', false)
            ->exists();
        if ($hayPendiente) {
            throw new RuntimeException('Ya hay un pago en línea en revisión para este pedido. Espera su aprobación o rechazo.');
        }

        // RF-M07-015, RF-M07-R-008: el pedido queda con el método y la pasarela aplicados.
        $pedido->forceFill(['metodo_pago_preferido' => 'en_linea', 'pasarela_preferida' => $data['pasarela']])->save();

        return $this->crearPago($pedido, [
            ...$data,
            'metodo' => 'en_linea',
            'pasarela' => $data['pasarela'],
            'caja_turno_id' => null,
            'estado' => $data['estado'] ?? 'pendiente',
            'registrado_por' => null,
        ]);
    }

    /**
     * Confirma un pago en línea que estaba pendiente (ej. tras validar el webhook de la pasarela).
     *
     * CORREGIDO (29/09/2026): este método ya existía pero nada lo invocaba — a diferencia de
     * ReservaService::confirmarDepositoEnLinea() (mismo patrón, sí expuesto por
     * ReservaController), no había ruta/Controller que lo llamara. Un Pedido pagado en línea
     * quedaba en 'pendiente' para siempre: PedidoService::confirmar() rechaza confirmar un
     * pedido de domicilio/recogida con un pago en línea sin aprobar (RN-M06-05), y sin este
     * método enganchado a una ruta no había ninguna forma de aprobarlo. Ver PagoController::
     * confirmarEnLinea()/rechazarEnLinea() y routes/api.php.
     */
    public function confirmarPagoEnLinea(Pago $pago): Pago
    {
        if ($pago->metodo !== 'en_linea') {
            throw new RuntimeException('Solo un pago en_linea se confirma con este método.');
        }
        if ($pago->estado !== 'pendiente') {
            throw new RuntimeException('Este pago no está pendiente de confirmar.');
        }

        return DB::transaction(function () use ($pago) {
            $pago->estado = 'aprobado';
            $pago->save();

            $this->finalizarSiQuedoCompleto($pago->pedido, null);

            return $pago;
        });
    }

    // Mismo patrón que ReservaService::rechazarDepositoEnLinea(): el pedido no se cancela
    // solo, sigue 'pendiente' sin un pago válido — el Cliente puede reintentar el pago en
    // línea (RegistrarPagoEnLineaRequest no bloquea un segundo intento) o el Mesero puede
    // cobrarlo presencial en su lugar.
    public function rechazarPagoEnLinea(Pago $pago): Pago
    {
        if ($pago->metodo !== 'en_linea') {
            throw new RuntimeException('Solo un pago en_linea se rechaza con este método.');
        }
        if ($pago->estado !== 'pendiente') {
            throw new RuntimeException('Este pago no está pendiente de rechazar.');
        }

        $pago->estado = 'rechazado';
        $pago->save();

        return $pago;
    }

    /**
     * Fase 2, tarea 2 (RF-M05-034, RN-M05-19, D7): abono del depósito de una reserva a un pedido de
     * la visita. Es un pago aprobado de tipo "deposito", con el método con el que se pagó el
     * depósito y SIN turno de caja: ese dinero ya entró a la caja cuando se recibió el depósito (o
     * por la pasarela). Lo llama AbonoDepositoService; nadie lo registra a mano.
     */
    public function registrarAbonoDeposito(Pedido $pedido, \App\Models\Reserva $reserva, float $monto, ?User $actor = null): Pago
    {
        return $this->crearPago($pedido, [
            'tipo' => 'deposito',
            'reserva_id' => $reserva->id,
            'metodo' => $reserva->deposito_metodo ?: 'efectivo',
            'pasarela' => $reserva->deposito_metodo === 'en_linea' ? $reserva->deposito_pasarela : null,
            'monto' => $monto,
            'caja_turno_id' => null,
            'estado' => 'aprobado',
            'registrado_por' => $actor?->id,
        ]);
    }

    private function crearPago(Pedido $pedido, array $data): Pago
    {
        return DB::transaction(function () use ($pedido, $data) {
            $monto = (float) $data['monto'];

            if ($monto <= 0) {
                throw new RuntimeException('El monto del pago debe ser mayor a 0.');
            }

            // RF-M06-011: la suma de pagos no anulados de un pedido nunca puede exceder su total.
            $yaPagado = $pedido->totalPagado();
            if (round($yaPagado + $monto, 2) > round((float) $pedido->total, 2)) {
                throw new RuntimeException('La suma de los pagos superaría el total del pedido (RF-M06-011).');
            }

            $pago = new Pago();
            $pago->pedido_id = $pedido->id;
            $pago->tipo = $data['tipo'] ?? 'cobro';
            $pago->reserva_id = $data['reserva_id'] ?? null;
            $pago->caja_turno_id = $data['caja_turno_id'];
            $pago->metodo = $data['metodo'];
            $pago->pasarela = $data['pasarela'] ?? null;
            $pago->monto = $monto;
            $pago->estado = $data['estado'];
            $pago->registrado_por = $data['registrado_por'];
            $pago->anulado = false;
            $pago->save();

            if ($pago->metodo === 'efectivo' && $pago->caja_turno_id) {
                $this->cajaService->recalcularEfectivoEsperado($pago->cajaTurno);
            }

            if ($pago->estado === 'aprobado') {
                $actorFinaliza = $data['registrado_por'] ? \App\Models\User::find($data['registrado_por']) : null;
                $this->finalizarSiQuedoCompleto($pedido, $actorFinaliza);
            }

            return $pago;
        });
    }

    // CORREGIDO (Fase 0, 09/10/2026): antes finalizaba con solo tener el pago completo, en
    // cualquier estado. Ahora delega en PedidoService::intentarFinalizar(), que además exige
    // PREPARANDO y, en domicilio y recogida, la entrega o la recogida confirmada (RN-PED-32).
    private function finalizarSiQuedoCompleto(Pedido $pedido, ?User $actor): void
    {
        $this->pedidoService->intentarFinalizar($pedido, $actor);
    }

    /**
     * RF-M06-003, RF-M06-004, RNF-M06-004: solo el Mesero registra el cobro presencial de
     * un pedido, sin importar el canal (local, domicilio contra entrega o recogida). El
     * Administrador nunca cobra — solo abre/cierra caja y autoriza anulaciones. El pago en
     * línea no pasa por aquí (lo hace el Cliente directo).
     */
    public function validarQuienCobra(Pedido $pedido, User $actor): void
    {
        $rolEsperado = $pedido->actorQueCobra();

        if ($rolEsperado === 'mesero' && ! $actor->esMesero()) {
            throw new RuntimeException('Solo el Mesero puede registrar cobros presenciales — local, domicilio contra entrega o recogida (RF-M06-003, RF-M06-004, RNF-M06-004).');
        }
    }

    /**
     * RF-M06-017/018/019, RN-M06-10: el Administrador anula o reembolsa un pago con motivo.
     * - Caja del día abierta: el pago deja de contar y el efectivo esperado se recalcula.
     * - Caja del día ya cerrada: exige autorización adicional (la contraseña del
     *   Administrador) y queda como ajuste extraordinario; las cifras del turno cerrado no
     *   se reescriben.
     *
     * CORREGIDO (Fase 0, tarea 3, 09/10/2026): antes recalculaba el efectivo esperado del
     * turno aunque ya estuviera cerrado, sin pedir nada, y cambiaba la cifra de un arqueo
     * que ya se había hecho.
     */
    public function anular(Pago $pago, User $actor, string $motivo, string $tipo = 'anulacion', ?string $passwordConfirmacion = null): Pago
    {
        if ($pago->anulado) {
            throw new RuntimeException('Este pago ya está anulado.');
        }
        if (! in_array($tipo, ['anulacion', 'reembolso'], true)) {
            throw new RuntimeException('El tipo debe ser anulacion o reembolso.');
        }

        // El turno del día del pago: el suyo si se cobró en caja, o el del día en que se
        // registró si fue en línea (los pagos en línea no llevan caja_turno_id, ADR-004).
        // Fase 2, tarea 2: el abono de un depósito no es dinero de ninguna caja (entró cuando se
        // recibió el depósito): anularlo no toca turnos ni deja ajuste extraordinario, y su valor
        // vuelve a quedar por abonar en la reserva.
        $esAbono = $pago->esAbonoDeDeposito();
        $turno = $esAbono ? null : ($pago->cajaTurno ?? $this->cajaService->turnoDeFecha($pago->created_at));
        $cajaCerrada = $turno !== null && ! $turno->estaAbierta();

        if ($cajaCerrada) {
            if (empty($passwordConfirmacion) || ! Hash::check($passwordConfirmacion, $actor->password)) {
                throw new RuntimeException('La caja de ese día ya cerró: confirma tu contraseña para registrar el ajuste extraordinario (RF-M06-019).');
            }
        }

        return DB::transaction(function () use ($pago, $actor, $motivo, $tipo, $turno, $cajaCerrada, $esAbono) {
            $pago->anulado = true;
            $pago->reverso_tipo = $tipo;
            $pago->anulado_motivo = $motivo;
            $pago->anulado_por = $actor->id;
            $pago->anulado_at = now();
            $pago->save();

            if ($esAbono) {
                app(AbonoDepositoService::class)->devolverAReserva($pago);
            }

            if ($cajaCerrada) {
                AjusteCaja::create([
                    'caja_turno_id' => $turno->id,
                    'pago_id' => $pago->id,
                    'tipo' => $tipo,
                    'metodo' => $pago->metodo,
                    'monto' => $pago->monto,
                    'motivo' => $motivo,
                    'autorizado_por' => $actor->id,
                ]);
            } elseif ($pago->metodo === 'efectivo' && $pago->caja_turno_id) {
                // RF-M06-018: anular un pago en efectivo del mismo día ajusta el esperado.
                $this->cajaService->recalcularEfectivoEsperado($pago->cajaTurno);
            }

            // RF-M06-020, RNF-M06-003: toda anulación o reembolso queda auditada.
            app(AuditoriaService::class)->registrar($tipo === 'reembolso' ? 'reembolsar_pago' : 'anular_pago', 'Pago', $pago->id, [
                'motivo' => $motivo,
                'monto' => (float) $pago->monto,
                'metodo' => $pago->metodo,
                'pedido_id' => $pago->pedido_id,
                'abono_de_reserva' => $esAbono ? $pago->reserva_id : null,
                'ajuste_extraordinario' => $cajaCerrada,
            ], $actor);

            // Decisión pendiente de confirmar con el usuario: si anular este pago hace que el
            // pedido ya no esté totalPagado(), este Service NO revierte automáticamente el
            // pedido de "finalizado" a otro estado — no hay una regla de negocio documentada
            // para ese caso. Se deja así a propósito en vez de asumir un comportamiento.

            return $pago;
        });
    }
}
