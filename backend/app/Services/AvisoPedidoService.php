<?php

namespace App\Services;

use App\Jobs\EnviarAvisoPedido;
use App\Models\Pedido;
use App\Models\PedidoAviso;
use App\Notifications\ListoParaRecogerNotification;
use Illuminate\Notifications\Notification as Aviso;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * M07 — Fase 4, tarea 5 (13/10/2026): avisos al cliente de un pedido por correo (decisión D3:
 * correo en V1, SMS después). Hoy: «listo para recoger» (RF-M07-R-012, RN-M07-R-03).
 *
 * Funciona como AvisoReservaService: programar() deja el aviso "pendiente" en pedido_avisos y
 * encola EnviarAvisoPedido al terminar la transacción; entregar() envía el correo y el aviso
 * queda enviado o, tras los reintentos, fallido con el error. Cada resultado queda en la
 * auditoría. Si el pedido no tiene correo (invitado que no lo dejó), no se programa nada y el
 * Mesero avisa por teléfono.
 */
class AvisoPedidoService
{
    public function programar(Pedido $pedido, string $tipo): ?PedidoAviso
    {
        if (! in_array($tipo, PedidoAviso::TIPOS, true)) {
            throw new InvalidArgumentException("Tipo de aviso desconocido: {$tipo}");
        }
        $correo = $pedido->correoDeContacto();
        if (! $correo) {
            return null;
        }

        $aviso = PedidoAviso::create([
            'pedido_id' => $pedido->id,
            'tipo' => $tipo,
            'canal' => 'correo',
            'destino' => $correo,
            'estado' => 'pendiente',
        ]);

        EnviarAvisoPedido::dispatch($aviso->id)->afterCommit();

        return $aviso;
    }

    /** Envía el correo ahora. Lanza la excepción si el envío falla (el trabajo decide si reintenta). */
    public function entregar(PedidoAviso $aviso): void
    {
        $pedido = $aviso->pedido()->with('cliente')->firstOrFail();
        $nombre = $pedido->cliente?->nombre ?? $pedido->cliente_invitado_nombre ?? $aviso->destino;

        Notification::route('mail', [$aviso->destino => $nombre])
            ->notifyNow($this->notificacion($aviso->tipo, $pedido));
    }

    public function marcarEnviado(PedidoAviso $aviso): void
    {
        $aviso->forceFill(['estado' => 'enviado', 'enviado_at' => now(), 'error' => null])->save();

        app(AuditoriaService::class)->registrar('enviar_aviso_pedido', 'Pedido', $aviso->pedido_id, [
            'aviso' => $aviso->tipo, 'canal' => $aviso->canal, 'destino' => $aviso->destino, 'intentos' => $aviso->intentos,
        ]);
    }

    public function marcarFallido(PedidoAviso $aviso, Throwable $error): void
    {
        $aviso->forceFill(['estado' => 'fallido', 'error' => mb_substr($error->getMessage(), 0, 1000)])->save();

        app(AuditoriaService::class)->registrar('falla_aviso_pedido', 'Pedido', $aviso->pedido_id, [
            'aviso' => $aviso->tipo, 'canal' => $aviso->canal, 'destino' => $aviso->destino,
            'intentos' => $aviso->intentos, 'error' => mb_substr($error->getMessage(), 0, 300),
        ]);
    }

    /** Vuelve a intentar un aviso que falló (botón "Reintentar aviso" en Pedidos). */
    public function reintentar(PedidoAviso $aviso): PedidoAviso
    {
        if ($aviso->estado !== 'fallido') {
            throw new RuntimeException('Solo se reintenta un aviso que falló.');
        }
        $aviso->forceFill(['estado' => 'pendiente', 'error' => null])->save();
        EnviarAvisoPedido::dispatch($aviso->id)->afterCommit();

        return $aviso;
    }

    private function notificacion(string $tipo, Pedido $pedido): Aviso
    {
        return match ($tipo) {
            'listo_para_recoger' => new ListoParaRecogerNotification($pedido),
        };
    }
}
