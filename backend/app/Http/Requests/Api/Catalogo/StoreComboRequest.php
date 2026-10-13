<?php

namespace App\Http\Requests\Api\Catalogo;

use Illuminate\Foundation\Http\FormRequest;

class StoreComboRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->esAdministrador() ?? false;
    }

    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:255'],
            // RF-M04-023, RN-M04-10 (Fase 2, tarea 3): mayor a cero.
            'precio' => ['required', 'numeric', 'gt:0'],
            // BUG corregido junto con esta tarea: la migración define tipo_venta como enum
            // bebida/alimento/otro (igual que en productos), pero esta regla aceptaba
            // cualquier string de hasta 50 caracteres — un valor fuera del enum pasaba la
            // validación (422 limpio) y luego reventaba en un 500 de SQL al intentar el
            // INSERT/UPDATE.
            'tipo_venta' => ['required', 'in:bebida,alimento,otro'],
            'vigencia_inicio' => ['nullable', 'date'],
            'vigencia_fin' => ['nullable', 'date', 'after_or_equal:vigencia_inicio'],
            'franja_inicio' => ['nullable', 'date_format:H:i:s'],
            'franja_fin' => ['nullable', 'date_format:H:i:s', 'after:franja_inicio'],
            'dias_semana' => ['nullable', 'array'],
            'dias_semana.*' => ['integer', 'between:1,7'],
            'activo' => ['boolean'],
            'productos' => ['required', 'array', 'min:1'],
            'productos.*.producto_id' => ['required', 'exists:productos,id'],
            'productos.*.cantidad' => ['required', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return ['precio.gt' => 'El precio debe ser mayor a cero.'];
    }
}
