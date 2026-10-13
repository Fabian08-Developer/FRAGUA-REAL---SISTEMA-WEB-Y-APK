<?php

namespace App\Http\Requests\Api\Pago;

use Illuminate\Foundation\Http\FormRequest;

class AnularPagoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // PagoPolicy::anular() se verifica en el Controller.
    }

    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'max:255'],
            // RF-M06-017: anular o reembolsar (por defecto, anular).
            'tipo' => ['nullable', 'in:anulacion,reembolso'],
            // RF-M06-019: solo se exige si la caja del día del pago ya cerró (lo decide PagoService).
            'password_confirmacion' => ['nullable', 'string'],
        ];
    }
}
