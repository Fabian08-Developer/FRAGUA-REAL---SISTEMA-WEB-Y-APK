<?php

namespace App\Http\Requests\Api\Usuario;

use Illuminate\Foundation\Http\FormRequest;

// El Administrador crea cuentas de staff (Mesero o Administrador) desde esta pantalla —
// el Cliente se autorregistra aparte (RegisterClienteRequest, AuthController::register()),
// por eso "role" solo acepta mesero/administrador acá, nunca cliente.
class StoreUsuarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->esAdministrador() ?? false;
    }

    public function rules(): array
    {
        return [
            'role' => ['required', 'in:mesero,administrador'],
            'nombre' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
