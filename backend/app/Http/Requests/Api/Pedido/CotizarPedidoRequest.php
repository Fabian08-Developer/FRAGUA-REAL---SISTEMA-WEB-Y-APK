<?php

namespace App\Http\Requests\Api\Pedido;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Fase 2, tarea 1 — RF-M04-035: cotización de un carrito (precios vigentes y promociones
 * automáticas) antes de crear el pedido. Pública, como crear el pedido; no guarda nada.
 */
class CotizarPedidoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tipo' => ['required', 'in:local,domicilio,recogida'],
            'lineas' => ['required', 'array', 'min:1', 'max:60'],
            'lineas.*.producto_id' => ['required_without:lineas.*.combo_id', 'nullable', 'exists:productos,id'],
            'lineas.*.combo_id' => ['required_without:lineas.*.producto_id', 'nullable', 'exists:combos,id'],
            'lineas.*.variante_id' => ['nullable', 'exists:variantes,id'],
            'lineas.*.cantidad' => ['required', 'integer', 'min:1'],
            'lineas.*.extras_ids' => ['nullable', 'array'],
            'lineas.*.extras_ids.*' => ['exists:extras,id'],
        ];
    }
}
