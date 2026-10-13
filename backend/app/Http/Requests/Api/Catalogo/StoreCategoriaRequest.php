<?php

namespace App\Http\Requests\Api\Catalogo;

use Illuminate\Foundation\Http\FormRequest;

class StoreCategoriaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->esAdministrador() ?? false;
    }

    public function rules(): array
    {
        return [
            // RF-M04-001: nombre único, con mensaje claro (Fase 2, tarea 3).
            'nombre' => ['required', 'string', 'max:255', \Illuminate\Validation\Rule::unique('categorias', 'nombre')->ignore($this->route('categoria'))],
            'activo' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return ['nombre.unique' => 'Ya existe una categoría con ese nombre.'];
    }
}
