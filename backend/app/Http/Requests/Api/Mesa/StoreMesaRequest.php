<?php

namespace App\Http\Requests\Api\Mesa;

use Illuminate\Foundation\Http\FormRequest;

class StoreMesaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->esAdministrador() ?? false;
    }

    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:255', 'unique:mesas,nombre'], // RN-M05-10
            'activa' => ['boolean'],
        ];
    }
}
