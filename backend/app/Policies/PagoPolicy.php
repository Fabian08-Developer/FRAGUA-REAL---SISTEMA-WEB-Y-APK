<?php

namespace App\Policies;

use App\Models\Pago;
use App\Models\User;

/**
 * Corrección técnica sobre el diseño inicial de esta revisión: Laravel resuelve la Policy
 * por la clase del modelo pasado a authorize(), así que solo las habilidades que reciben un
 * Pago YA EXISTENTE (como anular) pueden vivir aquí. "Cobrar" y "pagarEnLinea" autorizan
 * sobre un Pedido (antes de que el Pago exista) y por eso viven en PedidoPolicy — ver el
 * comentario ahí. Esta Policy sigue siendo el hallazgo original de la revisión del Documento
 * Técnico de Arquitectura: el flujo de la sección 3 usaba una PagoPolicy que nunca se había
 * entregado.
 */
class PagoPolicy
{
    // Control financiero: solo el Administrador anula un pago ya registrado.
    public function anular(User $user, Pago $pago): bool
    {
        return $user->esAdministrador();
    }

    // Confirmar/rechazar un pago en línea (mismo patrón y mismos roles que
    // ReservaPolicy para el depósito de una reserva): lo hace el Mesero o el
    // Administrador, nunca el Cliente que lo pagó — y solo aplica a metodo=en_linea
    // (un pago presencial ya nace 'aprobado' en PagoService::registrarCobro).
    public function confirmarEnLinea(User $user, Pago $pago): bool
    {
        return ($user->esAdministrador() || $user->esMesero()) && $pago->metodo === 'en_linea';
    }

    public function rechazarEnLinea(User $user, Pago $pago): bool
    {
        return $this->confirmarEnLinea($user, $pago);
    }
}
