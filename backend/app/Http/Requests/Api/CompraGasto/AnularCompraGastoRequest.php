<?php

namespace App\Http\Requests\Api\CompraGasto;

use Illuminate\Foundation\Http\FormRequest;

class AnularCompraGastoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->esAdministrador() ?? false;
    }

    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'max:255'],
            // Fase 2, tarea 5 (RN-M03-08): solo se pide si el día ya tiene cierre formal.
            'password_confirmacion' => ['nullable', 'string'],
        ];
    }
}
