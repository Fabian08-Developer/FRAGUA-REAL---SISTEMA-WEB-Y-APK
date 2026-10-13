<?php

namespace App\Providers;

use App\Models\CajaTurno;
use App\Models\Pago;
use App\Models\Pedido;
use App\Models\Reserva;
use App\Policies\CajaTurnoPolicy;
use App\Policies\PagoPolicy;
use App\Policies\PedidoPolicy;
use App\Policies\ReservaPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

/**
 * Registro completo de las Policies del proyecto (sección 5 del documento de arquitectura).
 * El snippet original del documento solo registraba Pedido::class — corregido aquí para
 * incluir también CajaTurno::class (ya entregada antes), Pago::class (el hallazgo de la
 * revisión: el flujo de la sección 3 la usaba sin que existiera) y Reserva::class (RN-M05-09:
 * "cancelar" necesitaba autorización fina — antes cualquier Cliente autenticado podía
 * cancelar la reserva de otro con solo conocer su id).
 */
class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        Pedido::class => PedidoPolicy::class,
        Pago::class => PagoPolicy::class,
        CajaTurno::class => CajaTurnoPolicy::class,
        Reserva::class => ReservaPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
