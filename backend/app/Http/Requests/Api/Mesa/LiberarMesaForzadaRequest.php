<?php

namespace App\Http\Requests\Api\Mesa;

use Illuminate\Foundation\Http\FormRequest;

// RF-M05-011: liberación forzada de una mesa (salta la validación de pedidos activos),
// exclusiva del Administrador y con motivo obligatorio para la auditoría.
class LiberarMesaForzadaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->esAdministrador() ?? false;
    }

    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'max:255'],
        ];
    }
}
