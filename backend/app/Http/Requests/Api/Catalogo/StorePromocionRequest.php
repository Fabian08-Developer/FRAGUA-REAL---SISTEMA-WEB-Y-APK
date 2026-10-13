<?php

namespace App\Http\Requests\Api\Catalogo;

use Illuminate\Foundation\Http\FormRequest;

class StorePromocionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->esAdministrador() ?? false;
    }

    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:255'],
            'tipo' => ['required', 'in:automatica,manual'],
            'alcance' => ['required', 'in:producto,categoria,total'],
            'tipo_valor' => ['required', 'in:porcentaje,fijo'],
            // RN-M04-23: siempre > 0; además <= 100 cuando es porcentaje (un descuento de
            // "150%" no tiene sentido, pero uno fijo de $150.000 sí puede serlo).
            'valor' => [
                'required',
                'numeric',
                'min:0.01',
                function ($attribute, $value, $fail) {
                    if ($this->input('tipo_valor') === 'porcentaje' && $value > 100) {
                        $fail('El porcentaje no puede ser mayor a 100.');
                    }
                },
            ],
            'vigencia_inicio' => ['nullable', 'date'],
            'vigencia_fin' => ['nullable', 'date', 'after_or_equal:vigencia_inicio'],
            'franja_inicio' => ['nullable', 'date_format:H:i:s'],
            'franja_fin' => ['nullable', 'date_format:H:i:s', 'after:franja_inicio'],
            'dias_semana' => ['nullable', 'array'],
            'dias_semana.*' => ['integer', 'between:1,7'],
            'activo' => ['boolean'],
            // RN-M04-19/20: solo se exigen cuando el alcance correspondiente los necesita.
            'productos' => ['required_if:alcance,producto', 'array'],
            'productos.*' => ['integer', 'exists:productos,id'],
            'categorias' => ['required_if:alcance,categoria', 'array'],
            'categorias.*' => ['integer', 'exists:categorias,id'],
        ];
    }
}
