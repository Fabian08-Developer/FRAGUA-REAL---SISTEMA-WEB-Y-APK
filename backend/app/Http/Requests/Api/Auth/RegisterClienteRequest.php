<?php

namespace App\Http\Requests\Api\Auth;

use App\Support\Usuarios\NombreUsuario;
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

    protected function prepareForValidation(): void
    {
        if ($this->has('username')) {
            $this->merge(['username' => NombreUsuario::normalizar($this->input('username'))]);
        }
    }

    public function messages(): array
    {
        return NombreUsuario::mensajes();
    }

    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            // Fase 3, tarea 3 (RF-M01-007/011): nombre de usuario opcional para iniciar sesión.
            'username' => NombreUsuario::reglas(),
            'telefono' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
