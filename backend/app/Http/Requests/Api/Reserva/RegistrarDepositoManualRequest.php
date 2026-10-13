<?php

namespace App\Http\Requests\Api\Reserva;

use Illuminate\Foundation\Http\FormRequest;

// RN-M05-16: el depósito de una reserva manual lo registra el Mesero o el Administrador,
// con cualquier método de cobro presencial de M06.
class RegistrarDepositoManualRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // El rol se filtra por la ruta (role:administrador,mesero).
    }

    public function rules(): array
    {
        return [
            // RN-M05-16: el depósito manual es presencial. CORREGIDO (Fase 1): antes aceptaba
            // también "en_linea", que es el pago del Cliente por la pasarela, no un registro manual.
            'metodo' => ['required', 'in:efectivo,tarjeta,transferencia'],
        ];
    }
}
