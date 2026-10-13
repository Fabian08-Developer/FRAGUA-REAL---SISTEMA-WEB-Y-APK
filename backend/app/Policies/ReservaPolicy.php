<?php

namespace App\Policies;

use App\Models\Reserva;
use App\Models\User;

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
    public function cancelar(User $user, Reserva $reserva): bool
    {
        if ($user->esAdministrador() || $user->esMesero()) {
            return true;
        }

        if (! $user->esCliente() || $reserva->cliente_correo !== $user->email) {
            return false;
        }

        // "antes de su hora": después, ya no aplica el reembolso automático de
        // RN-M05-17 y la excepción (no-show u otra) le corresponde resolverla al staff.
        return $reserva->fecha_hora->isFuture();
    }
}
