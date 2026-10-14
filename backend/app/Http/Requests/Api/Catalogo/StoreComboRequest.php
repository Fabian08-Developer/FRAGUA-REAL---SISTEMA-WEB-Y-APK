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
            // Fase 7, tarea 1: cada producto una sola vez (la cantidad va en su fila).
            'productos.*.producto_id' => ['required', 'distinct', 'exists:productos,id'],
            'productos.*.cantidad' => ['required', 'integer', 'min:1'],
        ];
    }

    // Fase 7, tarea 1 (RF-M04-013): un combo reúne dos o más productos. Puede ser el mismo producto
    // dos veces («2 × Perro Clásico») o productos distintos; con uno solo no es un combo.
    public function after(): array
    {
        return [function ($validator) {
            $productos = $this->input('productos');
            if (! is_array($productos) || $validator->errors()->has('productos*')) {
                return;
            }
            $unidades = array_sum(array_map(fn ($p) => (int) ($p['cantidad'] ?? 0), $productos));
            if ($unidades < 2) {
                $validator->errors()->add('productos', 'Un combo lleva al menos dos productos (pueden ser 2 del mismo).');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'precio.gt' => 'El precio debe ser mayor a cero.',
            'productos.*.producto_id.distinct' => 'Ese producto ya está en el combo: cambia su cantidad.',
        ];
    }
}
