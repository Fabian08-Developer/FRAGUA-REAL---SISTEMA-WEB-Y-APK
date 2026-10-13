<?php

namespace App\Services;

use App\Models\Mesa;
use App\Models\Reserva;
use App\Models\User;
use App\Support\Reservas\PoliticaReservas;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * M05 — Mesas y reservas con depósito obligatorio (RN-M05-14 a 19, Idea de Negocio:
 * "con depósito obligatorio"). Dos formas de pagar el depósito:
 * - En línea (RN-M05-16): el Cliente paga por Nequi/Bancolombia reutilizando el mismo
 *   mecanismo de pasarela de M06 (confirmar/rechazar/reintentar) aplicado a la reserva
 *   en vez de a un pedido — ver PagoService::registrarPagoEnLinea().
 * - Manual (RN-M05-16): el Mesero o el Administrador registran el pago con cualquier
 *   método de M06 (efectivo, tarjeta, transferencia).
 */
class ReservaService
{
    /**
     * Crea la reserva en estado pendiente_pago. El depósito exigido se copia como valor
     * vigente (RF-M05-028); igual que valor_domicilio en M07, el valor "vigente" todavía no
     * tiene una tabla `configuraciones` de la que leerse (ver README de la base de datos),
     * así que se recibe como parámetro hasta que esa tabla exista.
     */
    public function crear(array $data, float $depositoVigente, ?User $registradaPor = null): Reserva
    {
        // RN-M05-05 a 07 (Fase 1, tarea 4): solo mesas activas, y sin cruzarse con otra reserva
        // que ya aparta la mesa a esa hora.
        $mesa = Mesa::find($data['mesa_id']);
        if (! $mesa || ! $mesa->activa) {
            throw new RuntimeException('La mesa no existe o está desactivada.');
        }
        $fechaHora = Carbon::parse($data['fecha_hora']);
        if ($otra = $this->choque($mesa->id, $fechaHora)) {
            throw new RuntimeException("{$mesa->nombre} ya tiene una reserva a las {$this->hora($otra->fecha_hora)}. Elige otra hora u otra mesa.");
        }

        $reserva = new Reserva();
        $reserva->mesa_id = $data['mesa_id'];
        $reserva->cliente_nombre = $data['cliente_nombre'];
        $reserva->cliente_telefono = $data['cliente_telefono'];
        $reserva->cliente_correo = $data['cliente_correo'];
        $reserva->fecha_hora = $data['fecha_hora'];
        $reserva->estado = 'pendiente_pago';
        $reserva->deposito_valor = $depositoVigente;
        $reserva->deposito_pagado = false;
        $reserva->deposito_reembolsado = false;
        $reserva->deposito_aplicado_abono = false;
        $reserva->creado_por = $data['creado_por']; // cliente_web | mesero | administrador
        $reserva->registrada_por = $registradaPor?->id;
        $reserva->save();

        if ($registradaPor) {
            app(AuditoriaService::class)->registrar('crear_reserva_manual', 'Reserva', $reserva->id, [
                'mesa' => $mesa->nombre,
                'fecha_hora' => $reserva->fecha_hora->toIso8601String(),
            ], $registradaPor);
        }

        return $reserva;
    }

    /**
     * La reserva que ya aparta esta mesa a una hora que se cruza con $fechaHora, si existe.
     * Dos reservas se cruzan si empiezan a menos de `duracion_reserva_minutos` una de la otra.
     */
    public function choque(int $mesaId, CarbonInterface $fechaHora, ?int $excepto = null): ?Reserva
    {
        $duracion = (int) config('fragua_real.duracion_reserva_minutos', 120);

        return Reserva::queApartanMesa()
            ->where('mesa_id', $mesaId)
            ->when($excepto, fn ($q) => $q->where('id', '!=', $excepto))
            ->where('fecha_hora', '>', $fechaHora->copy()->subMinutes($duracion))
            ->where('fecha_hora', '<', $fechaHora->copy()->addMinutes($duracion))
            ->orderBy('fecha_hora')
            ->first();
    }

    /** @return Collection<int, Mesa> mesas activas sin reservas que se crucen con $fechaHora */
    public function mesasDisponibles(CarbonInterface $fechaHora): Collection
    {
        return Mesa::where('activa', true)->orderBy('nombre')->get()
            ->reject(fn (Mesa $mesa) => $this->choque($mesa->id, $fechaHora) !== null)
            ->values();
    }

    private function hora(CarbonInterface $momento): string
    {
        return $momento->copy()->setTimezone('America/Bogota')->format('g:i a');
    }

    // Al confirmar el depósito se vuelve a mirar: dos reservas web pudieron pedir la misma hora
    // sin pagar; la primera que paga se queda con la mesa.
    private function validarSinChoque(Reserva $reserva): void
    {
        if ($otra = $this->choque($reserva->mesa_id, $reserva->fecha_hora, $reserva->id)) {
            if ($otra->estado === 'confirmada') {
                throw new RuntimeException("La mesa ya quedó reservada a las {$this->hora($otra->fecha_hora)} por otra persona. Cancela esta reserva o cámbiala de mesa.");
            }
        }
    }

    /**
     * RN-M05-16: el Cliente inicia el pago del depósito en línea. Deja el depósito en
     * 'pendiente' — la reserva sigue pendiente_pago hasta que se confirme, igual que
     * PagoService::registrarPagoEnLinea() con un pedido.
     */
    public function registrarDepositoEnLinea(Reserva $reserva, string $pasarela): Reserva
    {
        if ($reserva->estado !== 'pendiente_pago') {
            throw new RuntimeException('Solo una reserva pendiente de pago puede iniciar el depósito.');
        }

        $reserva->deposito_pasarela = $pasarela;
        $reserva->deposito_estado = 'pendiente';
        $reserva->deposito_metodo = 'en_linea';
        $reserva->save();

        return $reserva;
    }

    /**
     * Confirma el depósito en línea (hoy: acción manual de Administrador/Mesero; mañana:
     * el webhook real de la pasarela — ver README-BACKEND.md, "Pasarela de pago real").
     * La mesa se marca 'reservada' recién aquí, no al crear la reserva — así no se
     * bloquea una mesa por una reserva sin pagar.
     */
    public function confirmarDepositoEnLinea(Reserva $reserva, ?User $actor = null): Reserva
    {
        if ($reserva->estado !== 'pendiente_pago' || $reserva->deposito_estado !== 'pendiente') {
            throw new RuntimeException('Esta reserva no tiene un depósito en línea pendiente de confirmar.');
        }
        $this->validarSinChoque($reserva);

        $reserva->deposito_pagado = true;
        $reserva->deposito_estado = 'aprobado';
        $reserva->deposito_pagado_at = now();
        $reserva->estado = 'confirmada';
        $reserva->save();

        $this->marcarMesaReservada($reserva);
        // Fase 4, tarea 4 (RF-M05-023): correo de confirmación con el enlace de la reserva.
        app(AvisoReservaService::class)->programar($reserva, 'confirmacion');

        // RF-M05-036: cada confirmación, rechazo o registro manual de un depósito queda auditado.
        app(AuditoriaService::class)->registrar('confirmar_deposito_reserva', 'Reserva', $reserva->id, [
            'monto' => (float) $reserva->deposito_valor, 'pasarela' => $reserva->deposito_pasarela,
        ], $actor);

        return $reserva;
    }

    // Rechaza el depósito en línea; la reserva sigue pendiente_pago para que el Cliente
    // pueda reintentar (mismo patrón de M06: confirmar/rechazar/reintentar).
    public function rechazarDepositoEnLinea(Reserva $reserva, ?User $actor = null): Reserva
    {
        if ($reserva->estado !== 'pendiente_pago' || $reserva->deposito_estado !== 'pendiente') {
            throw new RuntimeException('Esta reserva no tiene un depósito en línea pendiente de rechazar.');
        }

        $reserva->deposito_estado = 'rechazado';
        $reserva->save();

        app(AuditoriaService::class)->registrar('rechazar_deposito_reserva', 'Reserva', $reserva->id, [
            'monto' => (float) $reserva->deposito_valor, 'pasarela' => $reserva->deposito_pasarela,
        ], $actor);

        return $reserva;
    }

    // RN-M05-16: depósito manual registrado por el Mesero o el Administrador, con
    // cualquier método de cobro de M06.
    public function registrarDepositoManual(Reserva $reserva, string $metodo, User $registradoPor): Reserva
    {
        if ($reserva->estado !== 'pendiente_pago') {
            throw new RuntimeException('Solo una reserva pendiente de pago puede confirmarse.');
        }
        // RN-M05-16: el depósito manual es presencial (efectivo, tarjeta o transferencia).
        if (! in_array($metodo, ['efectivo', 'tarjeta', 'transferencia'], true)) {
            throw new RuntimeException('El depósito manual se registra en efectivo, tarjeta o transferencia (RN-M05-16).');
        }
        $this->validarSinChoque($reserva);

        // Fase 2, tarea 2 (RN-M06-02, RN-M06-08): el depósito manual es dinero que entra a la caja
        // del día, así que, como cualquier cobro, exige la caja abierta; en efectivo suma al
        // efectivo esperado de ese turno.
        $cajaService = app(CajaService::class);
        $turno = $cajaService->turnoAbierto();
        if (! $turno) {
            throw new RuntimeException('No hay un turno de caja abierto: el depósito entra a la caja del día (RN-M06-02).');
        }

        $reserva->deposito_pagado = true;
        $reserva->deposito_metodo = $metodo;
        $reserva->deposito_caja_turno_id = $turno->id;
        // Un depósito manual no pasa por una pasarela, aunque el método coincida con
        // "en_linea" en el catálogo de M06.
        $reserva->deposito_pasarela = null;
        $reserva->deposito_pagado_at = now();
        $reserva->deposito_registrado_por = $registradoPor->id;
        $reserva->estado = 'confirmada';
        $reserva->save();

        $this->marcarMesaReservada($reserva);
        app(AvisoReservaService::class)->programar($reserva, 'confirmacion'); // Fase 4, tarea 4
        if ($metodo === 'efectivo') {
            $cajaService->recalcularEfectivoEsperado($turno);
        }

        app(AuditoriaService::class)->registrar('registrar_deposito_reserva', 'Reserva', $reserva->id, [
            'monto' => (float) $reserva->deposito_valor, 'metodo' => $metodo, 'caja_turno_id' => $turno->id,
        ], $registradoPor);

        return $reserva;
    }

    // RN-M05-18: liberar por no-show (el cliente no llegó) — el depósito NO se reembolsa.
    // Fase 4, tarea 3 (RN-M05-07, RF-M05-016): solo cuando ya venció la tolerancia (hora de la
    // reserva + los minutos configurados). Lo hace el sistema solo cada minuto
    // (ReservaAutomaticaService) o una persona desde Reservas; antes se podía marcar no-show
    // incluso antes de la hora y el cliente perdía el depósito sin haber llegado tarde.
    public function marcarNoShow(Reserva $reserva, ?User $actor = null, bool $automatico = false): Reserva
    {
        if ($reserva->estado !== 'confirmada') {
            throw new RuntimeException('Solo una reserva confirmada puede marcarse como no-show.');
        }
        $libera = $this->horaDeLiberacion($reserva);
        if (now()->lt($libera)) {
            throw new RuntimeException("Todavía está dentro de la tolerancia: se puede marcar no-show desde las {$this->hora($libera)}.");
        }

        $reserva->estado = 'no_show';
        $reserva->no_show_at = now();
        $reserva->no_show_automatico = $automatico;
        $reserva->save();

        $this->sincronizarMesa($reserva->mesa_id);

        app(AuditoriaService::class)->registrar($automatico ? 'no_show_automatico' : 'marcar_no_show', 'Reserva', $reserva->id, [
            'hora_reserva' => $reserva->fecha_hora->copy()->setTimezone('America/Bogota')->format('Y-m-d H:i'),
            'tolerancia_minutos' => PoliticaReservas::toleranciaMinutos(),
            'deposito_retenido' => $reserva->deposito_pagado ? (float) $reserva->deposito_valor : 0,
        ], $actor);
        // Fase 4, tarea 4 (RF-M05-025): aviso de no-show al cliente.
        app(AvisoReservaService::class)->programar($reserva, 'no_show');

        return $reserva;
    }

    /** Hora + tolerancia: desde ahí la reserva se considera no honrada (RN-M05-07/08). */
    public function horaDeLiberacion(Reserva $reserva): CarbonInterface
    {
        return $reserva->fecha_hora->copy()->addMinutes(PoliticaReservas::toleranciaMinutos());
    }

    /**
     * La reserva confirmada que hoy aparta esta mesa: su hora está dentro de la ventana previa
     * (mesa Reservada desde N minutos antes) o ya pasó pero sigue dentro de la tolerancia.
     */
    public function reservaEnVentana(int $mesaId, ?CarbonInterface $momento = null): ?Reserva
    {
        $ahora = $momento ? Carbon::instance($momento) : now();

        return Reserva::where('mesa_id', $mesaId)
            ->where('estado', 'confirmada')
            ->where('fecha_hora', '<=', $ahora->copy()->addMinutes(PoliticaReservas::ventanaReservadaMinutos()))
            ->where('fecha_hora', '>', $ahora->copy()->subMinutes(PoliticaReservas::toleranciaMinutos()))
            ->orderBy('fecha_hora')
            ->first();
    }

    /**
     * Fase 4, tarea 3 (RF-M05-014, RN-M05-07): pone la mesa en el estado que le corresponde según
     * sus reservas. Una mesa libre pasa a Reservada solo dentro de la ventana previa a una reserva
     * confirmada; una mesa Reservada sin reserva en la ventana vuelve a Libre (si no tiene pedidos
     * activos). Una mesa Ocupada no se toca: los clientes que están sentados no se levantan; en
     * cuanto se libere, el siguiente proceso la marca Reservada si toca.
     */
    public function sincronizarMesa(?int $mesaId): ?string
    {
        $mesa = $mesaId ? Mesa::find($mesaId) : null;
        if (! $mesa || ! $mesa->activa) {
            return null;
        }

        $tieneReserva = $this->reservaEnVentana($mesa->id) !== null;

        if ($mesa->estado === 'libre' && $tieneReserva) {
            $mesa->estado = 'reservada';
            $mesa->save();

            return 'reservada';
        }
        if ($mesa->estado === 'reservada' && ! $tieneReserva && ! $mesa->tienePedidosActivos()) {
            $mesa->liberar();

            return 'libre';
        }

        return null;
    }

    // RN-M05-19: si el cliente sí llega, la mesa pasa de reservada a ocupada y el depósito
    // queda aplicado como abono al pedido de esa visita (RF-M05-015/034).
    // Fase 2, tarea 2: el abono ahora es real. Se marca la llegada y el depósito se abona como un
    // pago de tipo "deposito" a los pedidos de la visita que ya existan; si todavía no hay pedidos,
    // se abona al primero que se cree en la mesa (AbonoDepositoService). Antes solo se ponía
    // deposito_aplicado_abono en true y la cuenta de la mesa seguía cobrando el total completo.
    public function completar(Reserva $reserva, ?User $actor = null): Reserva
    {
        if ($reserva->estado !== 'confirmada') {
            throw new RuntimeException('Solo una reserva confirmada puede completarse.');
        }

        $mesa = Mesa::find($reserva->mesa_id);
        if ($mesa) {
            $mesa->ocupar();
        }

        $reserva->estado = 'completada';
        $reserva->llegada_at = now();
        $reserva->save();

        app(AuditoriaService::class)->registrar('confirmar_llegada_reserva', 'Reserva', $reserva->id, [
            'deposito' => $reserva->deposito_pagado ? (float) $reserva->deposito_valor : 0,
        ], $actor);

        app(AbonoDepositoService::class)->aplicar($reserva, $actor);

        return $reserva->fresh(['mesa']);
    }

    // RN-M05-17: cancelar antes de la hora reservada reembolsa el depósito completo.
    // Fase 4, tarea 4: queda en la auditoría (quién, o "por enlace" si fue el cliente desde el
    // correo) y se avisa al cliente por correo (RF-M05-025).
    public function cancelar(Reserva $reserva, ?User $actor = null, bool $porEnlace = false): Reserva
    {
        if (! in_array($reserva->estado, ['pendiente_pago', 'confirmada'], true)) {
            throw new RuntimeException('Esta reserva no puede cancelarse en su estado actual.');
        }

        // Fase 2, tarea 2 (RN-M06-08): devolver un depósito recibido en efectivo saca dinero de la
        // caja, así que exige un turno abierto y resta del efectivo esperado de ese turno. Los
        // depósitos en línea se devuelven por la pasarela y no pasan por la caja.
        $turnoReembolso = null;
        if ($reserva->deposito_pagado && $reserva->deposito_metodo === 'efectivo') {
            $turnoReembolso = app(CajaService::class)->turnoAbierto();
            if (! $turnoReembolso) {
                throw new RuntimeException('Para devolver un depósito en efectivo abre la caja del día: el dinero sale de ahí (RN-M05-17).');
            }
        }

        $reserva->estado = 'cancelada';
        if ($reserva->deposito_pagado) {
            $reserva->deposito_reembolsado = true;
            $reserva->deposito_reembolso_caja_turno_id = $turnoReembolso?->id;
        }
        $reserva->save();

        if ($turnoReembolso) {
            app(CajaService::class)->recalcularEfectivoEsperado($turnoReembolso);
        }

        $this->liberarMesa($reserva);

        app(AuditoriaService::class)->registrar('cancelar_reserva', 'Reserva', $reserva->id, array_filter([
            'por_enlace' => $porEnlace ?: null,
            'deposito_reembolsado' => $reserva->deposito_pagado ? (float) $reserva->deposito_valor : null,
        ], fn ($v) => $v !== null), $actor);
        app(AvisoReservaService::class)->programar($reserva, 'cancelacion');

        return $reserva;
    }

    // Fase 4, tarea 3: confirmar el depósito ya no marca la mesa Reservada de inmediato (antes lo
    // hacía aunque la reserva fuera para otro día, y hasta pisaba una mesa Ocupada). Ahora solo
    // si la reserva cae dentro de la ventana previa; si no, lo hace el proceso de cada minuto.
    private function marcarMesaReservada(Reserva $reserva): void
    {
        $this->sincronizarMesa($reserva->mesa_id);
    }

    private function liberarMesa(Reserva $reserva): void
    {
        $this->sincronizarMesa($reserva->mesa_id);
    }
}
