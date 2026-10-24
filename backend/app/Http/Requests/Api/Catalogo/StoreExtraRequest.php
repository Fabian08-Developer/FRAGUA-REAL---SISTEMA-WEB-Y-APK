<?php

namespace App\Http\Requests\Api\Catalogo;

use Illuminate\Foundation\Http\FormRequest;

class StoreExtraRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->esAdministrador() ?? false;
    }

    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:255'],
            // RF-M04-023, RN-M04-10 (Fase 2, tarea 3): mayor a cero.
            'precio' => ['required', 'numeric', 'gt:0', 'max:'.\App\Support\Validacion\Formatos::MAX_PESOS],
            'alcance' => ['required', 'in:general,especifico'],
            // RN-M04-05: "especifico" exige producto_id; "general" debe dejarlo vacío
            // (se asocia a productos al editarlos; sin asociar no se ofrece con ninguno).
            'producto_id' => ['required_if:alcance,especifico', 'prohibited_if:alcance,general', 'nullable', 'exists:productos,id'],
            'activo' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return ['precio.gt' => 'El precio debe ser mayor a cero.'];
    }
}
