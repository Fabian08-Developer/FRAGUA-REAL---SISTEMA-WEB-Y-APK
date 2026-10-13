<?php

namespace App\Policies;

use App\Models\Reserva;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Autorización fina sobre UNA reserva en particular (el middleware role:xxx ya filtra
 * por rol a nivel de ruta — ver sección 5 del documento de arquitectura).
 */
class ReservaPolicy
{
    // RN-M05-09: "Una reserva puede cancelarse antes de su hora por quien la creó, o por
    // el Mesero/Administrador." Reserva no tiene cliente_id (RN-M05-12: solo pide datos de
    // contacto, esté o no autenticado quien reserva) — "quien la creó" se identifica aquí
    // por el correo de contacto, el mismo que ReservaView.vue precarga con el email de la
    // cuenta cuando el Cliente tiene sesión iniciada.
    public function cancelar(User $user, Reserva $reserva): bool|Response
    {
        if ($user->esAdministrador() || $user->esMesero()) {
            return true;
        }

        if (! $user->esCliente() || $reserva->cliente_correo !== $user->email) {
            return false;
        }

        // CORREGIDO (revisión por rol, 13/10/2026): un depósito pagado en efectivo se devuelve en
        // la mano y sale de la caja del día (RN-M05-17, RN-M06-08). Desde "Mis pedidos" el Cliente
        // podía cancelarla y el sistema daba el dinero por devuelto sin que nadie se lo entregara.
        // Igual que el enlace de la reserva (ReservaPublicaController), se cancela en el local.
        if ($reserva->deposito_pagado && $reserva->deposito_metodo === 'efectivo') {
            return Response::deny('Pagaste el depósito en efectivo en el local: para cancelarla y recibir tu dinero, llama o pasa por el restaurante.');
        }

        // "antes de su hora": después, ya no aplica el reembolso automático de
        // RN-M05-17 y la excepción (no-show u otra) le corresponde resolverla al staff.
        return $reserva->fecha_hora->isFuture();
    }
}
