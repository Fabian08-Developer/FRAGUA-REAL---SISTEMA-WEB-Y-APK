<?php

namespace App\Policies;

use App\Models\Pedido;
use App\Models\User;

/**
 * Autorización fina sobre UN pedido en particular (el middleware role:xxx ya filtra por
 * rol a nivel de ruta — ver sección 5 del documento de arquitectura).
 */
class PedidoPolicy
{
    public function view(User $user, Pedido $pedido): bool
    {
        if ($user->esAdministrador() || $user->esMesero()) {
            return true;
        }

        // Un Cliente solo ve sus propios pedidos.
        return $pedido->cliente_id === $user->id;
    }

    // No hay una regla de negocio explícita en los documentos sobre quién confirma o pasa
    // a "preparando" un pedido; se asume que ambos roles operativos (Administrador y Mesero)
    // pueden hacerlo, ya que ninguno queda descrito como exclusivo para esta operación.
    public function confirmar(User $user, Pedido $pedido): bool
    {
        return $user->esAdministrador() || $user->esMesero();
    }

    public function marcarPreparando(User $user, Pedido $pedido): bool
    {
        return $user->esAdministrador() || $user->esMesero();
    }

    // RF-M02-T-016, RF-M07-025, RF-M07-R-013: los documentos nombran al Mesero para finalizar
    // un pedido y para confirmar entregas y recogidas. El Administrador supervisa, no opera el
    // piso (igual que en el cobro, RNF-M06-004).
    public function finalizar(User $user, Pedido $pedido): bool
    {
        return $user->esMesero();
    }

    public function confirmarEntrega(User $user, Pedido $pedido): bool
    {
        return $user->esMesero();
    }

    public function confirmarRecogida(User $user, Pedido $pedido): bool
    {
        return $user->esMesero();
    }

    // RN-PED-07 ("dentro de permisos"), HU-M02-002/004, HU-M02-020: el Mesero modifica los
    // pedidos que atiende y el Administrador interviene con los permisos de M01. El Cliente no
    // edita un pedido ya enviado: si cambió de idea, lo cancela mientras esté PENDIENTE.
    public function modificar(User $user, Pedido $pedido): bool
    {
        return $user->esAdministrador() || $user->esMesero();
    }

    // Fase 2, tarea 1 — RF-M04-036, HU-M04-015: el descuento manual lo aplican el Mesero o el
    // Administrador; el Cliente nunca (a él le llegan solas las promociones automáticas).
    public function aplicarDescuento(User $user, Pedido $pedido): bool
    {
        return $user->esAdministrador() || $user->esMesero();
    }

    public function verHistorial(User $user, Pedido $pedido): bool
    {
        return $user->esAdministrador() || $user->esMesero();
    }

    public function cancelar(User $user, Pedido $pedido): bool
    {
        if ($user->esAdministrador() || $user->esMesero()) {
            return true;
        }

        // RN-M07-04, RF-M07-016 y RF-M07-R-009 (Fase 1): el Cliente cancela su domicilio o su
        // recogida mientras esté PENDIENTE. Antes solo el domicilio; M07 también lo pide para la
        // recogida. Un pedido local (de mesa) no lo cancela el Cliente. Para el Mesero, lo que no
        // está PENDIENTE pide la contraseña de un Administrador (PedidoService::cancelar).
        return $user->esCliente()
            && $pedido->cliente_id === $user->id
            && in_array($pedido->tipo, ['domicilio', 'recogida'], true)
            && $pedido->estado === 'pendiente';
    }

    // ---- Cobro (RF-M06-003, RF-M06-004, RNF-M06-004) ----
    // Nota técnica: aunque conceptualmente "quién puede cobrar" es una regla de Pago, Laravel
    // resuelve la Policy por la clase del modelo que se autoriza — $this->authorize('cobrar',
    // $pedido) SIEMPRE consulta PedidoPolicy, nunca PagoPolicy, porque $pedido es un Pedido.
    // Por eso estas dos habilidades viven aquí y no en PagoPolicy (que solo aplica a un Pago
    // ya existente, ej. anular()). PagoService::validarQuienCobra() repite esta misma regla
    // como defensa en profundidad para cuando el Service se invoque fuera de un request HTTP.
    //
    // Regla real (RNF-M06-004): el Mesero registra todos los cobros presenciales, sin
    // importar el canal; el Administrador nunca cobra, solo abre/cierra caja y autoriza
    // anulaciones. Antes esta Policy tenía la regla al revés — corregido.
    public function cobrar(User $user, Pedido $pedido): bool
    {
        return $pedido->actorQueCobra() === 'mesero' && $user->esMesero();
    }

    public function pagarEnLinea(User $user, Pedido $pedido): bool
    {
        return $user->esCliente() && $pedido->cliente_id === $user->id;
    }
}
