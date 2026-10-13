<?php

namespace App\Http\Requests\Api\Pago;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Cobro presencial hecho por un empleado (Administrador o Mesero, según el canal).
 * Soporta pago mixto: el cliente envía uno o varios pagos para el mismo pedido.
 */
class RegistrarCobroRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // PagoPolicy::cobrar() se verifica en el Controller.
    }

    public function rules(): array
    {
        return [
            'metodo' => ['required', 'in:efectivo,tarjeta,transferencia'],
            'monto' => ['required', 'numeric', 'min:0.01'],
        ];
    }
}
