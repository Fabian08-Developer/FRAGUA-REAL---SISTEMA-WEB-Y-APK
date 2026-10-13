<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Pago\AnularPagoRequest;
use App\Http\Requests\Api\Pago\RegistrarCobroRequest;
use App\Http\Requests\Api\Pago\RegistrarPagoEnLineaRequest;
use App\Models\Pago;
use App\Models\Pedido;
use App\Services\PagoService;
use Illuminate\Http\Request;

/**
 * Implementa el flujo de cobro de M06:
 * routes/api.php (middleware role:xxx) → Controller → Form Request → Policy → Service → JSON.
 * El ejemplo original del documento de arquitectura ("Administrador cobra un pedido Local
 * en caja") no reflejaba la regla real de M06 — ver PedidoPolicy::cobrar() y
 * RF-M06-003/004, RNF-M06-004.
 */
class PagoController extends Controller
{
    public function __construct(private readonly PagoService $pagoService)
    {
    }

    // POST /api/pedidos/{pedido}/cobrar — cobro presencial, siempre por el Mesero (local,
    // domicilio contra entrega o recogida). El Administrador nunca cobra.
    public function cobrar(RegistrarCobroRequest $request, Pedido $pedido)
    {
        $this->authorize('cobrar', $pedido); // PedidoPolicy::cobrar() — RF-M06-003/004, RNF-M06-004

        $pago = $this->pagoService->registrarCobro($pedido, $request->user(), $request->validated());

        // Pedido completo (no solo los pagos): la pantalla Cobrar reemplaza la tarjeta con
        // esta respuesta, y el cobro puede haberlo finalizado (RN-PED-32).
        return response()->json($pedido->fresh(PedidoController::CON_DETALLES));
    }

    // POST /api/pedidos/{pedido}/pagar-en-linea — autoservicio del Cliente (Nequi/Bancolombia).
    public function pagarEnLinea(RegistrarPagoEnLineaRequest $request, Pedido $pedido)
    {
        $this->authorize('pagarEnLinea', $pedido);

        $pago = $this->pagoService->registrarPagoEnLinea($pedido, $request->validated());

        return response()->json($pago, 201);
    }

    // POST /api/pedidos/seguimiento/{token}/pagar-en-linea — el invitado paga con el token
    // de su pedido (RF-M06-005, Fase 0 tarea 4). El token reemplaza a la sesión.
    public function pagarEnLineaConToken(RegistrarPagoEnLineaRequest $request, string $token)
    {
        $pedido = Pedido::where('token_publico', $token)->firstOrFail();

        $pago = $this->pagoService->registrarPagoEnLinea($pedido, $request->validated());

        return response()->json($pago, 201);
    }

    public function anular(AnularPagoRequest $request, Pago $pago)
    {
        $this->authorize('anular', $pago);

        return response()->json($this->pagoService->anular(
            $pago,
            $request->user(),
            $request->motivo,
            $request->input('tipo') ?? 'anulacion',
            $request->input('password_confirmacion'),
        ));
    }

    // POST /api/pagos/{pago}/confirmar-en-linea — Mesero/Administrador aprueban manualmente
    // un pago en línea (mismo patrón que ReservaController::confirmarDepositoEnLinea, hasta
    // que exista el webhook real de la pasarela — ver README-BACKEND.md).
    public function confirmarEnLinea(Request $request, Pago $pago)
    {
        $this->authorize('confirmarEnLinea', $pago);

        return response()->json($this->pagoService->confirmarPagoEnLinea($pago));
    }

    public function rechazarEnLinea(Request $request, Pago $pago)
    {
        $this->authorize('rechazarEnLinea', $pago);

        return response()->json($this->pagoService->rechazarPagoEnLinea($pago));
    }
}
