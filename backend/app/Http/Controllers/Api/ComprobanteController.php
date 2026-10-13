<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
 */
class ComprobanteController extends Controller
{
    public function __construct(private readonly ComprobanteService $comprobantes)
    {
    }

    public function show(Request $request, Pedido $pedido)
    {
        $this->authorize('view', $pedido);

        return $this->responder($pedido, $request->query('formato'));
    }

    public function porEnlace(Request $request, string $token)
    {
        $pedido = Pedido::where('token_publico', $token)->firstOrFail();

        return $this->responder($pedido, $request->query('formato'));
    }

    private function responder(Pedido $pedido, ?string $formato)
    {
        $comprobante = $pedido->comprobante()->first();
        abort_if(! $comprobante, 404, 'Este pedido todavía no tiene comprobante: se genera cuando el pago queda completo.');

        $formato = in_array($formato, ['ticket', 'pdf'], true) ? $formato : ($pedido->esLocal() ? 'ticket' : 'pdf');

        return $this->comprobantes->documento($comprobante, $formato);
    }
}
