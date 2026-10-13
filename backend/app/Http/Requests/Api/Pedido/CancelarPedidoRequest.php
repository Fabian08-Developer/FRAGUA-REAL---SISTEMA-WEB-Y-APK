<?php

namespace App\Http\Requests\Api\Pedido;

use Illuminate\Foundation\Http\FormRequest;

class CancelarPedidoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // PedidoPolicy::cancelar() se verifica en el Controller.
    }

    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'max:255'],
            // Fase 1: autorización del Administrador cuando el Mesero cancela algo ya confirmado.
            'password_administrador' => ['nullable', 'string', 'max:255'],
        ];
    }
}
