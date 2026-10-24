<?php

namespace App\Http\Requests\Api\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * RF-M01: el usuario autenticado cambia su propia contraseña. Exige la contraseña
 * actual (verificada en el Controller con Hash::check, no aquí) para evitar que una
 * sesión robada/dejada abierta pueda tomar la cuenta con solo la contraseña nueva.
 */
class CambiarPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'password_actual' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', \Illuminate\Validation\Rules\Password::defaults()], // contraseña segura (RN-USR-23)
        ];
    }
}
