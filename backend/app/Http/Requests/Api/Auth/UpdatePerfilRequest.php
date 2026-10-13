<?php

namespace App\Http\Requests\Api\Auth;

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

    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:30'],
        ];
    }
}
