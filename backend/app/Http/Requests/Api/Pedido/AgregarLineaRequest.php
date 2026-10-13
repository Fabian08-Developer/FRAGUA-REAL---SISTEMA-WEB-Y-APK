<?php

namespace App\Http\Requests\Api\Pedido;

use Illuminate\Foundation\Http\FormRequest;

// RF-M02-L-003/012: una línea nueva para un pedido existente. Mismas reglas que cada línea
// de StorePedidoRequest; PedidoService valida disponibilidad, variante y extras.
class AgregarLineaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // PedidoPolicy::modificar() se verifica en el Controller.
    }

    public function rules(): array
    {
        return [
            'producto_id' => ['required_without:combo_id', 'nullable', 'exists:productos,id'],
            'combo_id' => ['required_without:producto_id', 'nullable', 'exists:combos,id'],
            'variante_id' => ['nullable', 'exists:variantes,id'],
            'cantidad' => ['required', 'integer', 'min:1', 'max:99'],
            'extras_ids' => ['nullable', 'array'],
            'extras_ids.*' => ['exists:extras,id'],
            'observaciones' => ['nullable', 'string', 'max:500'],
        ];
    }
}
