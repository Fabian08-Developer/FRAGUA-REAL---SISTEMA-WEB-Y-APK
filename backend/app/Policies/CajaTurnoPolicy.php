<?php

namespace App\Policies;

use App\Models\CajaTurno;
use App\Models\User;

/**
 * M06 — abrir y cerrar el turno de caja, y consultar sus cifras, es exclusivo del
 * Administrador (RN-M06-01): son datos financieros del negocio. Esto es distinto de
 * "quién cobra" un pedido (RF-M06-003/004, RNF-M06-004), que es siempre el Mesero — ver
 * PedidoPolicy::cobrar().
 */
class CajaTurnoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->esAdministrador();
    }

    public function view(User $user, CajaTurno $turno): bool
    {
        return $user->esAdministrador();
    }

    public function abrir(User $user): bool
    {
        return $user->esAdministrador();
    }

    public function cerrar(User $user, CajaTurno $turno): bool
    {
        return $user->esAdministrador();
    }
}
