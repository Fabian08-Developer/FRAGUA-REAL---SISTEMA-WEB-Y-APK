<?php

namespace App\Http\Requests\Api\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Autorregistro público, exclusivo para Clientes. Administrador y Mesero no se autorregistran
 * (M01 no describe un endpoint público para crear esos roles) — los crea un Administrador ya
 * autenticado a través de un endpoint aparte, fuera del alcance de este documento.
 */
class RegisterClienteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
