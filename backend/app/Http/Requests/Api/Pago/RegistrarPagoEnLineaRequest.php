<?php

namespace App\Http\Requests\Api\Pago;

use Illuminate\Foundation\Http\FormRequest;

class RegistrarPagoEnLineaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // PagoPolicy::pagarEnLinea() se verifica en el Controller.
    }

    public function rules(): array
    {
        return [
            'pasarela' => ['required', 'in:nequi,bancolombia'],
            'monto' => ['required', 'numeric', 'min:0.01'],
        ];
    }
}
