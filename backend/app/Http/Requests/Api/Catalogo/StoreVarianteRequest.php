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
            // Mejora (16/10/2026): lo que trae la presentación ("para 3 o 4 personas"). La foto se sube
            // aparte (POST /variantes/{id}/foto), igual que la del producto.
            'descripcion' => ['nullable', 'string', 'max:160'],
            // RF-M04-023, RN-M04-10 (Fase 2, tarea 3): mayor a cero.
            'precio' => ['required', 'numeric', 'gt:0', 'max:'.\App\Support\Validacion\Formatos::MAX_PESOS],
            'activa' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'precio.gt' => 'El precio debe ser mayor a cero.',
            'descripcion.max' => 'La descripción de la presentación admite hasta 160 caracteres.',
        ];
    }
}
