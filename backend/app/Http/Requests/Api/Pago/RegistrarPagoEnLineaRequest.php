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
            'pasarela' => ['required', 'in:nequi,bancolombia,breb'],
            // El monto lo fija el servidor (lo que falta por pagar); se acepta por compatibilidad.
            'monto' => ['nullable', 'numeric', 'min:0.01'],
            // Pago directo (22/10/2026, RF-M06-031): la captura del comprobante y la referencia.
            ...\App\Services\PagoDirectoService::reglasComprobante(),
        ];
    }

    public function messages(): array
    {
        return \App\Services\PagoDirectoService::mensajesComprobante();
    }

    // Pago directo: sin Wompi, solo los medios que el negocio configuró (RN-M06-16).
    public function after(): array
    {
        return [function ($validator) {
            if (is_string($this->pasarela) && ($error = app(\App\Services\PagoDirectoService::class)->errorDeMedio($this->pasarela))) {
                $validator->errors()->add('pasarela', $error);
            }
        }];
    }
}
