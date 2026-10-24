<?php

namespace App\Http\Requests\Api\Auth;

use App\Support\Usuarios\NombreUsuario;
use Illuminate\Foundation\Http\FormRequest;

/**
 * RF-M01: el usuario autenticado edita sus propios datos (nombre/teléfono).
 * El email no se edita aquí a propósito: es el identificador de login y cambiarlo
 * implicaría revalidar unicidad y podría romper el token/sesión actual — fuera de
 * alcance por ahora (ver README-BACKEND.md si se decide agregarlo más adelante).
 */
class UpdatePerfilRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // cualquier usuario autenticado edita SU PROPIO perfil (ver AuthController::actualizarPerfil).
    }

    protected function prepareForValidation(): void
    {
        // 22/10/2026: el teléfono se guarda solo con números y el nombre sin espacios de más.
        \App\Support\Validacion\Formatos::normalizar($this, ['telefono' => 'telefono', 'nombre' => 'nombre']);
        if ($this->has('username')) {
            $this->merge(['username' => NombreUsuario::normalizar($this->input('username'))]);
        }
    }

    public function messages(): array
    {
        return NombreUsuario::mensajes() + [
            'telefono.regex' => \App\Support\Validacion\Formatos::MENSAJE_CELULAR,
            'nombre.regex' => \App\Support\Validacion\Formatos::MENSAJE_PERSONA,
        ];
    }

    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:80', 'regex:'.\App\Support\Validacion\Formatos::PERSONA],
            'telefono' => ['nullable', 'string', 'regex:'.\App\Support\Validacion\Formatos::CELULAR],
            // Fase 3, tarea 3 (RF-M01-016): cada quien elige o cambia su nombre de usuario.
            // "sometimes": si el formulario no lo envía, no se toca.
            'username' => ['sometimes', ...NombreUsuario::reglas($this->user()?->id)],
        ];
    }
}
