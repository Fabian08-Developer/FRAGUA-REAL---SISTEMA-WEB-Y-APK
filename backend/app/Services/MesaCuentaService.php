<?php

namespace App\Services;

use App\Http\Controllers\Api\PedidoController;
use App\Models\Mesa;
use App\Models\Pedido;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Fase 1, tarea 3 del plan de implementación (11/10/2026): cuenta acumulada de la mesa y cobro
 * por mesa. RF-M02-L-018/019/020, RN-PED-13/14/30, HU-M02-006 (M02); RF-M06-003/011 (M06).
 *
 * La cuenta reúne los pedidos locales de la visita en curso (desde que la mesa pasó a Ocupada,
 * ver Mesa::ocupar()), sin los cancelados. Cada pago sigue perteneciendo a UN pedido (ADR-003):
 * cobrar la mesa reparte el monto entre sus pedidos con saldo, del más antiguo al más nuevo, y
 * cada pedido finaliza por su cuenta cuando cumple RN-PED-32.
 */
class MesaCuentaService
{
    public function __construct(private readonly PagoService $pagoService)
    {
    }

    /**
     * @param  Collection<int, Pedido>|null  $pedidos  los de una cuenta ya leída (al cobrar, la
     *                                                mesa puede quedar libre y su visita cerrada)
     */
    public function resumen(Mesa $mesa, ?Collection $pedidos = null): array
    {
        $pedidos ??= $mesa->pedidosDeLaCuenta()->get();
        $pedidos = Pedido::with(PedidoController::CON_DETALLES)->whereKey($pedidos->modelKeys())->orderBy('id')->get();

        $total = round((float) $pedidos->sum('total'), 2);
        $pagado = round($pedidos->sum(fn (Pedido $p) => $this->pagado($p)), 2);
        $mesa->refresh();

        return [
            'mesa' => $mesa,
            'abierta' => $mesa->estado === 'ocupada',
            'ocupada_desde' => $mesa->ocupada_desde?->toIso8601String(),
            'pedidos' => $pedidos,
            'total' => $total,
            'pagado' => $pagado,
            'saldo' => round(max(0, $total - $pagado), 2),
            'pedidos_activos' => $pedidos->whereIn('estado', ['pendiente', 'confirmado', 'preparando'])->count(),
        ];
    }

    /**
     * RF-M02-L-021, RF-M06-003: el Mesero cobra lo que debe la mesa (o una parte, para pagos
     * mixtos) con un solo método. Nunca más que el saldo (RF-M06-011).
     *
     * @return array el resumen de la cuenta después del cobro
     */
    public function cobrar(Mesa $mesa, User $actor, string $metodo, float $monto): array
    {
        return DB::transaction(function () use ($mesa, $actor, $metodo, $monto) {
            $mesa = Mesa::whereKey($mesa->id)->lockForUpdate()->firstOrFail();
            $pedidos = $mesa->pedidosDeLaCuenta()->get();

            if ($pedidos->isEmpty()) {
                throw new RuntimeException('Esta mesa no tiene una cuenta abierta.');
            }

            $saldo = round($pedidos->sum(fn (Pedido $p) => $this->saldo($p)), 2);
            $monto = round($monto, 2);
            if ($monto <= 0) {
                throw new RuntimeException('El monto del pago debe ser mayor a 0.');
            }
            if ($saldo <= 0) {
                throw new RuntimeException('La cuenta de esta mesa ya está pagada.');
            }
            if ($monto > $saldo) {
                throw new RuntimeException('El monto supera lo que debe la mesa ($'.number_format($saldo, 0, ',', '.').') (RF-M06-011).');
            }

            $restante = $monto;
            foreach ($pedidos as $pedido) {
                $parte = round(min($this->saldo($pedido), $restante), 2);
                if ($parte <= 0) {
                    continue;
                }
                // registrarCobro valida quién cobra y la caja abierta, y finaliza el pedido si ya
                // cumple todo; si algo falla, la transacción deshace el cobro completo.
                $this->pagoService->registrarCobro($pedido->fresh(), $actor, ['metodo' => $metodo, 'monto' => $parte]);
                $restante = round($restante - $parte, 2);
                if ($restante <= 0) {
                    break;
                }
            }

            return $this->resumen($mesa, $pedidos);
        });
    }

    private function pagado(Pedido $pedido): float
    {
        return (float) $pedido->pagos->where('anulado', false)->where('estado', 'aprobado')->sum('monto');
    }

    private function saldo(Pedido $pedido): float
    {
        return max(0, (float) $pedido->total - $pedido->totalPagado());
    }
}
