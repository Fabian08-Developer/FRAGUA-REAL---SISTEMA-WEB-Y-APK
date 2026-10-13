<?php

namespace App\Http\Requests\Api\Reserva;

use Illuminate\Foundation\Http\FormRequest;

// RN-M05-16: inicia el pago del depósito en línea (Nequi/Bancolombia), reutilizando el
// mismo par de pasarelas de M06. El nombre de la clase quedó del diseño anterior
// (confirmar por un solo método); su contenido ahora es "registrar depósito en línea".
class ConfirmarDepositoReservaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pasarela' => ['required', 'in:nequi,bancolombia'],
            // Fase 6, tarea 1: el enlace secreto que recibió quien creó la reserva.
            'token' => ['required', 'string', 'max:64'],
        ];
    }
}
