<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comprobante;
use App\Models\Pedido;
use App\Services\ComprobanteService;
use Illuminate\Http\Request;

/**
 * M06 — Fase 5, tarea 1: RF-M06-009/010, RN-M06-07, HU-M06-006, RF-M02-L-023, RF-M02-T-013,
 * HU-M02-019. Descarga o impresión del comprobante vigente de un pedido:
 *  - ?formato=ticket → ticket de 80 mm (lo normal en un pedido local, para imprimir);
 *  - ?formato=pdf → PDF A5 (lo normal en domicilio y recogida).
 * Sin formato, el que corresponde al canal. El personal ve cualquier pedido; el Cliente, los
 * suyos (PedidoPolicy::view); el invitado, el de su enlace.
 *
 * Reimpresión (decisión del usuario, 13/10/2026): cuando lo pide el personal cuenta como impresión
 * (auditoría `imprimir_comprobante`) y, desde la segunda, sale marcado "REIMPRESIÓN". Lo que abre
 * el cliente o el invitado no cuenta ni se marca. El Administrador además ve todos los comprobantes
 * del pedido, también los anulados, y abre cualquiera (sale con su sello ANULADO).
 */
class ComprobanteController extends Controller
{
    private const SIN_COMPROBANTE = 'Este pedido todavía no tiene comprobante: se genera cuando el pago queda completo.';

    public function __construct(private readonly ComprobanteService $comprobantes)
    {
    }

    public function show(Request $request, Pedido $pedido)
    {
        $this->authorize('view', $pedido);

        $comprobante = $pedido->comprobante()->first();
        abort_if(! $comprobante, 404, self::SIN_COMPROBANTE);

        return $this->entregar($request, $pedido, $comprobante);
    }

    // Historia completa de comprobantes del pedido (vigente y anulados), solo Administrador.
    public function index(Request $request, Pedido $pedido)
    {
        $this->authorize('verComprobantes', $pedido);

        return response()->json(['data' => $pedido->comprobantes()->reorder('id', 'desc')->get()]);
    }

    // Cualquier comprobante del pedido, también uno anulado, solo Administrador.
    public function mostrar(Request $request, Pedido $pedido, Comprobante $comprobante)
    {
        $this->authorize('verComprobantes', $pedido);
        abort_if($comprobante->pedido_id !== $pedido->id, 404);

        return $this->entregar($request, $pedido, $comprobante);
    }

    public function porEnlace(Request $request, string $token)
    {
        $pedido = Pedido::where('token_publico', $token)->firstOrFail();

        return $this->responder($pedido, $request->query('formato'));
    }

    private function responder(Pedido $pedido, ?string $formato)
    {
        $comprobante = $pedido->comprobante()->first();
        abort_if(! $comprobante, 404, self::SIN_COMPROBANTE);

        return $this->comprobantes->documento($comprobante, $this->formato($pedido, $formato));
    }

    // El personal imprime (cuenta y se marca desde la segunda vez); el Cliente solo lo ve.
    private function entregar(Request $request, Pedido $pedido, Comprobante $comprobante)
    {
        $usuario = $request->user();
        $formato = $this->formato($pedido, $request->query('formato'));

        return $usuario->esMesero() || $usuario->esAdministrador()
            ? $this->comprobantes->imprimir($comprobante, $formato, $usuario)
            : $this->comprobantes->documento($comprobante, $formato);
    }

    private function formato(Pedido $pedido, ?string $formato): string
    {
        return in_array($formato, ['ticket', 'pdf'], true) ? $formato : ($pedido->esLocal() ? 'ticket' : 'pdf');
    }
}
