<?php

namespace App\Http\Requests\Api\Usuario;

use App\Support\Usuarios\NombreUsuario;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * M01 — Fase 3, tarea 2 (12/10/2026): el Administrador corrige los datos de una cuenta y su rol.
 * RF-M01-003 (actualizar datos), RF-M01-005 y RF-M01-022 (solo roles del catálogo),
 * RF-M01-011 y RNF-M01-004 (correo único), RF-M01-021 (obligatorios y formato),
 * CA-M01-003.1 (solo campos permitidos: la contraseña no se cambia aquí).
 */
class UpdateUsuarioRequest extends FormRequest
{
    // Catálogo de roles del sistema (RNF-TRANS-011, RN-USR-01/14/15).
    public const ROLES = ['administrador', 'mesero', 'cliente'];

    public function authorize(): bool
    {
        return true; // la ruta ya exige role:administrador
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'nombre' => is_string($this->nombre) ? trim($this->nombre) : $this->nombre,
            'email' => is_string($this->email) ? trim($this->email) : $this->email,
            'telefono' => is_string($this->telefono) && trim($this->telefono) === '' ? null : $this->telefono,
        ]);
        if ($this->has('username')) {
            $this->merge(['username' => NombreUsuario::normalizar($this->input('username'))]);
        }
    }

    public function rules(): array
    {
        $usuario = $this->route('usuario');

        return [
            'nombre' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($usuario?->id)],
            'telefono' => ['nullable', 'string', 'max:30'],
            // Fase 3, tarea 3 (RF-M01-007/011): vacío = sin nombre de usuario (entra con el correo);
            // si no se envía, no se toca.
            'username' => ['sometimes', ...NombreUsuario::reglas($usuario?->id)],
            'role' => ['required', Rule::in(self::ROLES)],
            // Fase 7, tarea 2 (RF-M03-32): permiso de compras y gastos, solo para un Mesero.
            'puede_registrar_gastos' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            ...NombreUsuario::mensajes(),
            'email.unique' => 'Ese correo ya lo usa otra cuenta.',
            'role.in' => 'Ese rol no existe. Los roles del sistema son Administrador, Mesero y Cliente.',
        ];
    }
}
