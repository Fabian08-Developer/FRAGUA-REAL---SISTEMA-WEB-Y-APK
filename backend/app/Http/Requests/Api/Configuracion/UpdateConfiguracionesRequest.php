<?php

namespace App\Http\Requests\Api\Configuracion;

use Illuminate\Foundation\Http\FormRequest;

class UpdateConfiguracionesRequest extends FormRequest
{
    public function authorize(): bool
    {
        // La ruta ya está detrás de role:administrador (routes/api.php).
        return true;
    }

    public function rules(): array
    {
        return [
            'valor_domicilio' => ['required', 'numeric', 'min:0'],
            'deposito_reserva' => ['required', 'numeric', 'min:0'],
        ];
    }
}
