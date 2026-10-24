<?php

namespace App\Http\Requests\Api\Caja;

use Illuminate\Foundation\Http\FormRequest;

class CerrarCajaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->esAdministrador() ?? false;
    }

    public function rules(): array
    {
        return [
            'efectivo_contado' => ['required', 'numeric', 'min:0', 'max:'.\App\Support\Validacion\Formatos::MAX_PESOS],
            // Solo obligatoria si hay descuadre; CajaService la exige en ese caso (RF-M06-015/016).
            'justificacion_descuadre' => ['nullable', 'string', 'max:500'],
        ];
    }
}
