<?php

namespace App\Notifications;

use App\Models\Reserva;
use App\Support\Reservas\PoliticaReservas;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * M05 — RF-M05-024, RN-M05-12/13 (Fase 4, tarea 3, 13/10/2026): recordatorio por correo antes
 * de la hora de la reserva (decisión D3: correo en V1; el SMS queda para después). Va en la cola
 * para no frenar el proceso de cada minuto si el servidor de correo tarda.
 */
class RecordatorioReservaNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly Reserva $reserva)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $hora = $this->reserva->fecha_hora->copy()->setTimezone('America/Bogota');
        $mesa = $this->reserva->mesa?->nombre;
        $tolerancia = PoliticaReservas::toleranciaMinutos();

        return (new MailMessage)
            ->subject('Te esperamos hoy a las '.$hora->format('g:i a').' — Fragua Real Burger')
            ->greeting('Hola '.$this->reserva->cliente_nombre.',')
            ->line('Te recordamos tu reserva en Fragua Real Burger para el '.$hora->locale('es')->isoFormat('dddd D [de] MMMM [a las] h:mm a').($mesa ? " ({$mesa})." : '.'))
            ->line("Te guardamos la mesa hasta {$tolerancia} minutos después de esa hora. Si no alcanzas a llegar, la reserva se libera y el depósito no se devuelve.")
            ->line('Si necesitas cancelar, hazlo antes de la hora y te devolvemos el depósito completo.');
    }
}
