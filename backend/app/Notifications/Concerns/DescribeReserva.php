<?php

namespace App\Notifications\Concerns;

use App\Models\Reserva;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Fase 4, tarea 4: partes comunes de los correos de reserva (fecha en español y hora de
 * Colombia, valor del depósito y el enlace para ver o cancelar la reserva sin cuenta).
 */
trait DescribeReserva
{
    protected function fechaTexto(Reserva $reserva): string
    {
        return $reserva->fecha_hora->copy()->setTimezone('America/Bogota')->locale('es')
            ->isoFormat('dddd D [de] MMMM [a las] h:mm a');
    }

    protected function horaCorta(Reserva $reserva): string
    {
        return $reserva->fecha_hora->copy()->setTimezone('America/Bogota')->format('g:i a');
    }

    protected function pesos(float|string|null $valor): string
    {
        return '$'.number_format((float) $valor, 0, ',', '.');
    }

    protected function mensajeBase(Reserva $reserva, string $asunto): MailMessage
    {
        return (new MailMessage)
            ->subject($asunto.' — Fragua Real Burger')
            ->greeting('Hola '.$reserva->cliente_nombre.',');
    }

    /** Botón al enlace público de la reserva (ver o cancelar sin cuenta). */
    protected function conEnlace(MailMessage $mensaje, Reserva $reserva, string $texto = 'Ver o cancelar mi reserva'): MailMessage
    {
        return $reserva->token_publico
            ? $mensaje->action($texto, $reserva->enlacePublico())
            : $mensaje;
    }
}
