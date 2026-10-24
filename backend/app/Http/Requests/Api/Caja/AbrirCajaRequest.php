<?php

namespace App\Http\Requests\Api\Caja;

use Illuminate\Foundation\Http\FormRequest;

class AbrirCajaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->esAdministrador() ?? false;
    }

    public function rules(): array
    {
        return [
            'base_inicial' => ['required', 'numeric', 'min:0', 'max:'.\App\Support\Validacion\Formatos::MAX_PESOS],
        ];
    }
}
