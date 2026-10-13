<?php

namespace App\Notifications;

use App\Models\Pedido;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * M07 — RF-M07-R-012, RN-M07-R-03, HU-M07-012 (Fase 4, tarea 5): el pedido para recoger ya está
 * listo. Dice cuánto falta por pagar (al recoger, en efectivo o tarjeta) y lleva el enlace de
 * seguimiento del pedido. Correo en V1; el SMS queda para después (decisión D3).
 */
class ListoParaRecogerNotification extends Notification
{
    public function __construct(public readonly Pedido $pedido)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $p = $this->pedido;
        $nombre = $p->cliente?->nombre ?? $p->cliente_invitado_nombre;
        $saldo = max(0, (float) $p->total - $p->totalPagado());

        $mensaje = (new MailMessage)
            ->subject("Tu pedido #{$p->id} está listo para recoger — Fragua Real Burger")
            ->greeting($nombre ? "Hola {$nombre}," : 'Hola,')
            ->line("Tu pedido #{$p->id} ya está listo. Te esperamos en Fragua Real Burger para que lo recojas.")
            ->line($saldo > 0
                ? 'Al recogerlo pagas $'.number_format($saldo, 0, ',', '.').' en efectivo o con tarjeta.'
                : 'Tu pedido ya está pagado: solo di tu nombre o el número del pedido.');

        return $p->token_publico ? $mensaje->action('Ver mi pedido', $p->enlaceSeguimiento()) : $mensaje;
    }
}
