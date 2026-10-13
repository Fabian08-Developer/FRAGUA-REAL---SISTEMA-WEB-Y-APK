<?php

namespace App\Http\Requests\Api\Catalogo;

use Illuminate\Foundation\Http\FormRequest;

class StoreVarianteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->esAdministrador() ?? false;
    }

    public function rules(): array
    {
        return [
            'producto_id' => ['required', 'exists:productos,id'],
            'nombre' => ['required', 'string', 'max:255'],
            // RF-M04-023, RN-M04-10 (Fase 2, tarea 3): mayor a cero.
            'precio' => ['required', 'numeric', 'gt:0'],
            'activa' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return ['precio.gt' => 'El precio debe ser mayor a cero.'];
    }
}
