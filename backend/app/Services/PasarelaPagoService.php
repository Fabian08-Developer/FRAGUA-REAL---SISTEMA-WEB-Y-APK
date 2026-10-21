<?php

namespace App\Services;

use App\Models\Pago;
use App\Models\Pedido;
use App\Models\Reserva;
use App\Models\TransaccionPasarela;
use App\Models\User;
use App\Support\Pasarela\PasarelaNoDisponibleException;
use App\Support\Pasarela\PasarelaPago;
use App\Support\Pasarela\ResultadoPasarela;
use App\Support\Pasarela\ResultadoReembolso;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * M06 — Fase 6, tarea 1 (13/10/2026, decisión D4: Wompi): pago en línea con confirmación
 * automática. RF-M06-006 (invocar la pasarela y esperar su respuesta), RF-M06-007 y RN-M06-05 (el
 * pedido no pasa a CONFIRMADO sin el pago aprobado: lo sigue exigiendo PedidoService::confirmar),
 * RF-M06-008 (reintentar tras un rechazo), RF-M06-017 (reembolso), RF-M07-013 a 015 (método y
 * pasarela aplicados), RF-M05-029/030 (el depósito de la reserva usa este mismo camino).
 *
 * Cómo funciona:
 *  1. El Cliente elige pagar en línea. PagoService (o ReservaService, para el depósito) deja el
 *     pago "pendiente" como siempre e iniciar() crea la transacción con una referencia secreta y
 *     devuelve el enlace del checkout de la pasarela.
 *  2. El Cliente paga allá. La pasarela avisa con un evento firmado (procesarEvento) y además lo
 *     devuelve a /pago/retorno/{referencia}, que consulta el estado (estadoParaRetorno).
 *  3. aplicar() confirma o rechaza el pago o el depósito, una sola vez, aunque el evento llegue
 *     repetido o después de la consulta. Si algo no cuadra (monto distinto, un pago aprobado que ya
 *     se había rechazado a mano o dado por abandonado, una reserva que ya no puede confirmarse), no
 *     decide solo con el dinero: lo marca para que el Administrador lo revise.
 *  4. conciliar() (cada 5 minutos) pregunta por los que siguen pendientes, por si un evento se
 *     perdió, y da por abandonados los que nunca llegaron a la pasarela.
 *
 * Respaldo (decisión D4): si la pasarela no está configurada (PASARELA_PROVEEDOR=manual o faltan
 * llaves), activa() es falso y todo sigue como antes: el pago queda en revisión y lo aprueba el
 * Mesero o el Administrador. Con la pasarela activa, esa aprobación manual sigue disponible.
 */
class PasarelaPagoService
{
    public function __construct(private readonly PasarelaPago $pasarela)
    {
    }

    public function activa(): bool
    {
        return config('fragua_real.pasarela.proveedor') === $this->pasarela->nombre() && $this->pasarela->configurada();
    }

    public function proveedor(): string
    {
        return $this->pasarela->nombre();
    }

    /**
     * Crea el intento en la pasarela para un pago de pedido o para el depósito de una reserva y
     * devuelve lo que el navegador necesita para mandar al Cliente a pagar.
     *
     * @return array{proveedor: string, referencia: string, url: string, expira_at: string}
     */
    public function iniciar(Pago|Reserva $pagable, float $monto, ?string $preferida, array $pagador = []): array
    {
        $prefijo = $pagable instanceof Reserva ? 'FRR' : 'FRP';
        $transaccion = TransaccionPasarela::create([
            'proveedor' => $this->pasarela->nombre(),
            // Secreta: con ella la página de retorno muestra el estado sin pedir sesión.
            'referencia' => $prefijo.'-'.$pagable->getKey().'-'.Str::upper(Str::random(20)),
            'pagable_type' => $pagable->getMorphClass(),
            'pagable_id' => $pagable->getKey(),
            'monto' => round($monto, 2),
            'moneda' => 'COP',
            'pasarela_preferida' => $preferida,
            'estado' => 'pendiente',
            'expira_at' => now()->addMinutes(max(5, (int) config('fragua_real.pasarela.minutos_checkout', 30))),
        ]);

        return [
            'proveedor' => $transaccion->proveedor,
            'referencia' => $transaccion->referencia,
            'url' => $this->pasarela->urlCheckout($transaccion, $this->urlRetorno($transaccion), $pagador),
            'expira_at' => $transaccion->expira_at->toIso8601String(),
        ];
    }

    public function urlRetorno(TransaccionPasarela $transaccion): string
    {
        // La pasarela agrega ?id=<transacción> al volver.
        return rtrim((string) config('fragua_real.frontend_url'), '/').'/pago/retorno/'.$transaccion->referencia;
    }

    /**
     * RF-M06-008: antes de un intento nuevo, se pregunta a la pasarela por el anterior que sigue
     * pendiente. Si se rechazó o nunca se pagó, se cierra y se puede seguir; si sigue en curso o
     * ya quedó aprobado, se avisa en vez de cobrar dos veces.
     */
    public function liberarParaReintento(TransaccionPasarela $transaccion): void
    {
        $resultado = $this->consultarPasarela($transaccion);

        if ($resultado) {
            $transaccion = $this->aplicar($transaccion, $resultado, 'consulta');
        } else {
            $transaccion = $this->abandonar($transaccion, 'El Cliente volvió a intentar el pago sin haber pagado el anterior.');
        }

        if ($transaccion->estado === 'pendiente') {
            throw new RuntimeException('Tu pago anterior sigue en proceso en la pasarela. Si pagas con Nequi, apruébalo en la app; si no, espera unos minutos y vuelve a intentar.');
        }
        if ($transaccion->estado === 'aprobado') {
            throw new RuntimeException('Tu pago anterior ya quedó aprobado. No hace falta pagar de nuevo.');
        }
    }

    /**
     * POST /api/pasarela/wompi/eventos. Lanza FirmaInvalidaException si la firma no es válida.
     * Devuelve qué se hizo: aplicado | ignorado | desconocido | vencido.
     */
    public function procesarEvento(array $evento, array $encabezados = []): string
    {
        $resultado = $this->pasarela->leerEvento($evento, $encabezados);
        if (! $resultado || ! $resultado->referencia) {
            return 'ignorado';
        }

        // Endurecimiento (13/10/2026): la firma incluye el timestamp, así que no se puede cambiar;
        // un evento muy viejo (o del futuro) no se aplica. El estado real llega por la conciliación.
        $momento = (int) ($evento['timestamp'] ?? 0);
        $maximo = max(1, (int) config('fragua_real.pasarela.eventos_max_horas', 72)) * 3600;
        $ahora = now()->getTimestamp();
        if ($momento < $ahora - $maximo || $momento > $ahora + 600) {
            Log::warning('Evento de la pasarela fuera de tiempo: no se aplica.', ['referencia' => $resultado->referencia, 'timestamp' => $momento]);

            return 'vencido';
        }

        $transaccion = TransaccionPasarela::where('proveedor', $this->pasarela->nombre())->where('referencia', $resultado->referencia)->first();
        if (! $transaccion) {
            // Un pago hecho desde otro sistema con la misma cuenta, o una referencia de pruebas.
            Log::warning('Evento de la pasarela con una referencia que no es de este sistema.', ['referencia' => $resultado->referencia]);

            return 'desconocido';
        }

        $this->aplicar($transaccion, $resultado, 'evento');

        return 'aplicado';
    }

    /**
     * GET /api/pasarela/transacciones/{referencia}: lo que ve el Cliente al volver de la pasarela.
     * Si sigue pendiente, consulta a la pasarela (con el id que trae la URL de retorno, si viene).
     */
    public function estadoParaRetorno(string $referencia, ?string $transaccionId = null): array
    {
        $transaccion = TransaccionPasarela::where('referencia', $referencia)->firstOrFail();

        if (! $transaccion->esFinal() && $this->activa()) {
            try {
                $resultado = $transaccionId
                    ? $this->pasarela->consultar($transaccionId)
                    : $this->consultarPasarela($transaccion);
                // El id de la URL lo puede cambiar cualquiera: solo vale si es de esta referencia.
                if ($resultado && $resultado->referencia === $transaccion->referencia) {
                    $transaccion = $this->aplicar($transaccion, $resultado, 'retorno');
                }
            } catch (PasarelaNoDisponibleException) {
                // Se muestra "pendiente"; la página vuelve a preguntar y el evento llega igual.
            }
        }

        return $this->resumenParaCliente($transaccion->fresh());
    }

    /**
     * El corazón: deja la transacción con lo que dice la pasarela y confirma o rechaza el pago o el
     * depósito. Idempotente: el mismo estado dos veces no hace nada la segunda.
     */
    public function aplicar(TransaccionPasarela $transaccion, ResultadoPasarela $resultado, string $origen): TransaccionPasarela
    {
        return DB::transaction(function () use ($transaccion, $resultado, $origen) {
            /** @var TransaccionPasarela $tx */
            $tx = TransaccionPasarela::whereKey($transaccion->id)->lockForUpdate()->firstOrFail();
            $anterior = $tx->estado;

            $tx->transaccion_id = $resultado->transaccionId ?? $tx->transaccion_id;
            $tx->metodo_pago = $resultado->metodo ?? $tx->metodo_pago;
            $tx->estado_proveedor = $resultado->estadoProveedor ?? $tx->estado_proveedor;
            $tx->respuesta = $resultado->datos ?: $tx->respuesta;
            if ($origen === 'evento') {
                $tx->eventos++;
                $tx->ultimo_evento_at = now();
            }

            if ($resultado->estado === 'pendiente' || $resultado->estado === $anterior) {
                $tx->save();

                return $tx;
            }

            if ($tx->esFinal()) {
                // Después de cerrada. El único cambio esperado: una aprobada que se anuló (reembolso).
                if ($anterior === 'aprobado' && $resultado->estado === 'anulado') {
                    $tx->estado = 'anulado';
                    if ($tx->reembolso_estado === 'solicitado') {
                        $tx->reembolso_estado = 'aprobado';
                    }
                    $tx->save();

                    return $tx;
                }
                $this->marcarRevision($tx, $resultado->estado === 'aprobado'
                    ? "La pasarela aprobó un pago que el sistema ya había cerrado como {$anterior}: revisa si hay que devolver el dinero o aplicarlo a mano."
                    : "La pasarela cambió el pago de {$anterior} a {$resultado->estado} después de cerrado.");
                $tx->save();

                return $tx;
            }

            $tx->estado = $resultado->estado;
            $tx->finalizada_at = now();

            if ($resultado->estado === 'aprobado' && $resultado->montoCentavos !== null && $resultado->montoCentavos !== $tx->montoEnCentavos()) {
                $this->marcarRevision($tx, 'La pasarela aprobó un monto distinto ('.number_format($resultado->montoCentavos / 100, 0, ',', '.').') al del pago: no se confirmó solo.');
                $tx->save();

                return $tx;
            }
            $tx->save();

            $pagable = $tx->pagable()->first();
            if ($pagable instanceof Pago) {
                $this->aplicarAPago($tx, $pagable, $resultado->estado === 'aprobado');
            } elseif ($pagable instanceof Reserva) {
                $this->aplicarAReserva($tx, $pagable, $resultado->estado === 'aprobado');
            }

            return $tx->fresh();
        });
    }

    /**
     * RF-M06-017, RN-M05-17: devuelve por la pasarela un pago o un depósito aprobado. Si la
     * pasarela no lo permite para ese medio (Nequi, botón Bancolombia), queda dicho para hacerlo
     * desde su panel. Nunca deshace la anulación ni la cancelación que lo pidió.
     */
    public function reembolsar(TransaccionPasarela $transaccion, ?User $actor = null): TransaccionPasarela
    {
        if ($transaccion->estado !== 'aprobado') {
            throw new RuntimeException('Solo se devuelve por la pasarela un pago que quedó aprobado.');
        }
        if (in_array($transaccion->reembolso_estado, ['solicitado', 'aprobado'], true)) {
            return $transaccion;
        }

        try {
            $resultado = $this->pasarela->reembolsar($transaccion);
        } catch (\Throwable $e) {
            report($e);
            $resultado = new ResultadoReembolso('fallido', 'No se pudo pedir el reembolso a la pasarela. Vuelve a intentarlo en unos minutos.');
        }

        $transaccion->forceFill([
            'reembolso_estado' => $resultado->estado,
            'reembolso_id' => $resultado->id,
            'reembolso_mensaje' => mb_substr($resultado->mensaje, 0, 255),
            'reembolso_respuesta' => $resultado->datos ?: null,
            'reembolso_at' => now(),
        ])->save();

        app(AuditoriaService::class)->registrar('reembolso_pasarela', $this->entidadAuditoria($transaccion), $transaccion->pagable_id, [
            'referencia' => $transaccion->referencia,
            'monto' => (float) $transaccion->monto,
            'resultado' => $resultado->estado,
            'mensaje' => $resultado->mensaje,
        ], $actor);

        // Si la pasarela no lo devolvió, el Administrador lo ve en Caja > Pagos en línea por revisar.
        if (in_array($resultado->estado, ['fallido', 'no_soportado'], true)) {
            $this->marcarRevision($transaccion, $resultado->estado === 'fallido'
                ? 'El reembolso por la pasarela falló: reinténtalo o devuélvelo desde el panel de Wompi.'
                : 'Devuelve este pago desde el panel de Wompi o por transferencia (el medio no admite reembolso por API).');
        }

        return $transaccion;
    }

    /**
     * Un pedido con pago en línea aprobado se canceló: el dinero ya se cobró y hay que devolverlo.
     * No se reembolsa solo (la cancelación puede ser un error que se corrige), pero queda marcado
     * para que el Administrador lo reembolse desde Caja. Lo llama PedidoService::cancelar.
     */
    public function avisarPedidoCancelado(Pedido $pedido): void
    {
        $pedido->pagos()->where('metodo', 'en_linea')->where('estado', 'aprobado')->where('anulado', false)
            ->with('transaccionPasarela')->get()
            ->each(function (Pago $pago) {
                $tx = $pago->transaccionPasarela;
                if ($tx && $tx->estado === 'aprobado' && ! $tx->reembolso_estado) {
                    $this->marcarRevision($tx, 'Se canceló un pedido ya pagado en línea: reembolsa el pago desde Caja.');
                }
            });
    }

    // El Administrador revisó una transacción marcada (con lo que hizo, en la nota).
    public function marcarRevisada(TransaccionPasarela $transaccion, User $actor, string $nota): TransaccionPasarela
    {
        if (! $transaccion->requiere_revision || $transaccion->revisado_at) {
            throw new RuntimeException('Esta transacción no está pendiente de revisión.');
        }
        $transaccion->forceFill(['revisado_por' => $actor->id, 'revisado_at' => now()])->save();

        app(AuditoriaService::class)->registrar('revisar_pago_pasarela', $this->entidadAuditoria($transaccion), $transaccion->pagable_id, [
            'referencia' => $transaccion->referencia, 'motivo' => $transaccion->motivo_revision, 'nota' => $nota,
        ], $actor);

        return $transaccion;
    }

    /**
     * Cada 5 minutos (routes/console.php, "pasarela:conciliar"): pregunta por las transacciones
     * pendientes con más de 2 minutos, por si un evento se perdió, y da por abandonadas las que
     * vencieron sin llegar a la pasarela.
     *
     * @return array{revisadas: int, cerradas: int, abandonadas: int, sin_respuesta: int}
     */
    public function conciliar(): array
    {
        $cuenta = ['revisadas' => 0, 'cerradas' => 0, 'abandonadas' => 0, 'sin_respuesta' => 0];
        if (! $this->activa()) {
            return $cuenta;
        }

        TransaccionPasarela::where('proveedor', $this->pasarela->nombre())
            ->where('estado', 'pendiente')
            ->where('created_at', '<=', now()->subMinutes(2))
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->each(function (TransaccionPasarela $tx) use (&$cuenta) {
                $cuenta['revisadas']++;
                try {
                    $resultado = $this->consultarPasarela($tx);
                } catch (PasarelaNoDisponibleException) {
                    $cuenta['sin_respuesta']++;

                    return;
                }
                if ($resultado) {
                    if ($this->aplicar($tx, $resultado, 'conciliacion')->esFinal()) {
                        $cuenta['cerradas']++;
                    }
                } elseif ($tx->expira_at && $tx->expira_at->addMinutes(5)->isPast()) {
                    $this->abandonar($tx, 'El enlace de pago venció sin que el Cliente pagara.');
                    $cuenta['abandonadas']++;
                }
            });

        return $cuenta;
    }

    /** Lo que se muestra de una transacción en Caja, Reservas o Mis pedidos (sin la respuesta cruda). */
    public static function resumen(?TransaccionPasarela $tx): ?array
    {
        if (! $tx) {
            return null;
        }

        return [
            'id' => $tx->id,
            'proveedor' => $tx->proveedor,
            'referencia' => $tx->referencia,
            'estado' => $tx->estado,
            'metodo_pago' => $tx->metodo_pago,
            'transaccion_id' => $tx->transaccion_id,
            'requiere_revision' => $tx->requiere_revision && ! $tx->revisado_at,
            'motivo_revision' => $tx->motivo_revision,
            'revisado_at' => $tx->revisado_at?->toIso8601String(),
            'reembolso_estado' => $tx->reembolso_estado,
            'reembolso_mensaje' => $tx->reembolso_mensaje,
            'created_at' => $tx->created_at?->toIso8601String(),
        ];
    }

    // ───────────── internos ─────────────

    private function consultarPasarela(TransaccionPasarela $tx): ?ResultadoPasarela
    {
        $resultado = $tx->transaccion_id
            ? $this->pasarela->consultar($tx->transaccion_id)
            : $this->pasarela->buscarPorReferencia($tx->referencia);

        return $resultado && $resultado->referencia === $tx->referencia ? $resultado : null;
    }

    private function abandonar(TransaccionPasarela $transaccion, string $motivo): TransaccionPasarela
    {
        return DB::transaction(function () use ($transaccion, $motivo) {
            $tx = TransaccionPasarela::whereKey($transaccion->id)->lockForUpdate()->firstOrFail();
            if ($tx->esFinal()) {
                return $tx;
            }
            $tx->forceFill(['estado' => 'abandonado', 'finalizada_at' => now(), 'estado_proveedor' => $tx->estado_proveedor ?? 'SIN_TRANSACCION'])->save();

            $pagable = $tx->pagable()->first();
            if ($pagable instanceof Pago && $pagable->estado === 'pendiente' && ! $pagable->anulado) {
                app(PagoService::class)->rechazarPagoEnLinea($pagable, null, 'pasarela', $motivo);
            } elseif ($pagable instanceof Reserva && $pagable->deposito_estado === 'pendiente' && $pagable->estado === 'pendiente_pago') {
                app(ReservaService::class)->rechazarDepositoEnLinea($pagable, null, 'pasarela');
            }

            return $tx;
        });
    }

    private function aplicarAPago(TransaccionPasarela $tx, Pago $pago, bool $aprobado): void
    {
        $pagos = app(PagoService::class);

        if ($aprobado) {
            if ($pago->estado === 'pendiente' && ! $pago->anulado) {
                // El dinero llegó: el pago queda aprobado aunque el pedido se haya cancelado
                // mientras tanto, para poder devolverlo desde Caja (Reembolsar).
                $pagos->confirmarPagoEnLinea($pago, null, 'pasarela');
                if ($pago->pedido()->value('estado') === 'cancelado') {
                    $this->marcarRevision($tx, 'La pasarela aprobó el pago de un pedido que ya estaba cancelado: reembólsalo desde Caja.');
                }
            } elseif ($pago->estado !== 'aprobado' || $pago->anulado) {
                $this->marcarRevision($tx, 'La pasarela aprobó un pago que en el sistema ya estaba '.($pago->anulado ? 'anulado' : 'rechazado').': revisa si hay que devolver el dinero.');
            }
            // Aprobado a mano antes y ahora la pasarela lo confirma: todo cuadra.

            return;
        }

        if ($pago->estado === 'pendiente' && ! $pago->anulado) {
            $pagos->rechazarPagoEnLinea($pago, null, 'pasarela');
        } elseif ($pago->estado === 'aprobado' && ! $pago->anulado) {
            $this->marcarRevision($tx, 'El pago se aprobó a mano, pero la pasarela lo rechazó: revisa si el dinero llegó.');
        }
    }

    private function aplicarAReserva(TransaccionPasarela $tx, Reserva $reserva, bool $aprobado): void
    {
        $reservas = app(ReservaService::class);
        $pendiente = $reserva->estado === 'pendiente_pago' && $reserva->deposito_estado === 'pendiente';

        if ($aprobado) {
            if ($pendiente) {
                try {
                    DB::transaction(fn () => $reservas->confirmarDepositoEnLinea($reserva, null, 'pasarela'));
                } catch (RuntimeException $e) {
                    // Por ejemplo, otra persona ya pagó esa mesa a esa hora (RN-M05-07).
                    $this->marcarRevision($tx, 'La pasarela aprobó el depósito, pero la reserva no se pudo confirmar: '.$e->getMessage().' Devuelve el depósito o cambia la reserva de mesa.');
                }
            } elseif (! ($reserva->deposito_pagado && $reserva->deposito_metodo === 'en_linea')) {
                $this->marcarRevision($tx, 'La pasarela aprobó el depósito de una reserva que ya no lo esperaba (estado: '.$reserva->estado.'): revisa si hay que devolverlo.');
            }

            return;
        }

        if ($pendiente) {
            $reservas->rechazarDepositoEnLinea($reserva, null, 'pasarela');
        } elseif ($reserva->deposito_pagado && $reserva->deposito_metodo === 'en_linea' && $reserva->deposito_confirmado_via === 'manual') {
            $this->marcarRevision($tx, 'El depósito se aprobó a mano, pero la pasarela lo rechazó: revisa si el dinero llegó.');
        }
    }

    private function marcarRevision(TransaccionPasarela $tx, string $motivo): void
    {
        $tx->forceFill(['requiere_revision' => true, 'motivo_revision' => mb_substr($motivo, 0, 255), 'revisado_por' => null, 'revisado_at' => null])->save();

        app(AuditoriaService::class)->registrar('pago_pasarela_por_revisar', $this->entidadAuditoria($tx), $tx->pagable_id, [
            'referencia' => $tx->referencia, 'estado' => $tx->estado, 'monto' => (float) $tx->monto, 'motivo' => $motivo,
        ]);
        app(NotificacionService::class)->pagoPorRevisar($tx); // M11
    }

    private function entidadAuditoria(TransaccionPasarela $tx): string
    {
        return $tx->pagable_type === (new Reserva())->getMorphClass() ? 'Reserva' : 'Pago';
    }

    private function resumenParaCliente(TransaccionPasarela $tx): array
    {
        $pagable = $tx->pagable()->first();
        $datos = [
            'referencia' => $tx->referencia,
            'estado' => $tx->estado,
            'monto' => (float) $tx->monto,
            'metodo_pago' => $tx->metodo_pago,
            'en_revision' => $tx->requiere_revision && ! $tx->revisado_at,
            'tipo' => $pagable instanceof Reserva ? 'reserva' : 'pedido',
        ];

        if ($pagable instanceof Pago) {
            /** @var Pedido|null $pedido */
            $pedido = $pagable->pedido()->first();
            $datos['pedido'] = $pedido ? [
                'id' => $pedido->id,
                'tipo' => $pedido->tipo,
                'estado' => $pedido->estado,
                'total' => (float) $pedido->total,
                'de_cliente' => $pedido->cliente_id !== null,
                // El invitado no tiene sesión: su enlace de seguimiento es la única forma de volver.
                'seguimiento' => $pedido->cliente_id === null ? $pedido->token_publico : null,
            ] : null;
        } elseif ($pagable instanceof Reserva) {
            $pagable->loadMissing('mesa');
            $datos['reserva'] = [
                'id' => $pagable->id,
                'estado' => $pagable->estado,
                'fecha_hora' => $pagable->fecha_hora?->toIso8601String(),
                'mesa' => $pagable->mesa?->nombre,
                // Quien pagó vuelve a su reserva con este enlace (para verla o reintentar el depósito).
                'enlace' => in_array($pagable->estado, ['confirmada', 'pendiente_pago'], true) ? $pagable->token_publico : null,
            ];
        }

        return $datos;
    }
}
