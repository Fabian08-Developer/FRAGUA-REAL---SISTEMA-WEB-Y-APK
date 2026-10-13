<?php

namespace App\Http\Requests\Api\Pedido;

use Illuminate\Foundation\Http\FormRequest;

// RF-M02-L-004/006: cambiar cantidad u observaciones de una línea aún no preparada.
class ActualizarLineaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // PedidoPolicy::modificar() se verifica en el Controller.
    }

    public function rules(): array
    {
        return [
            'cantidad' => ['required_without:observaciones', 'integer', 'min:1', 'max:99'],
            'observaciones' => ['nullable', 'string', 'max:500'],
        ];
    }
}
