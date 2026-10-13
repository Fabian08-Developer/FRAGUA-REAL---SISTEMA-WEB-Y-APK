<?php

namespace App\Http\Requests\Api\Pedido;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Fase 2, tarea 1 — RF-M04-036/041, RN-M04-22/23, HU-M04-015: descuento manual a un pedido.
 * Se da un porcentaje o un valor fijo, o se elige una promoción de tipo "manual" ya preparada
 * por el Administrador. El motivo siempre es obligatorio. Quién puede hacerlo lo decide
 * PedidoPolicy::aplicarDescuento (Mesero y Administrador).
 */
class AplicarDescuentoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $porPromocion = $this->filled('promocion_id');

        return [
            'promocion_id' => ['nullable', 'integer', Rule::exists('promociones', 'id')->where('tipo', 'manual')],
            'tipo_valor' => [Rule::requiredIf(! $porPromocion), 'nullable', 'in:porcentaje,fijo'],
            'valor' => [
                Rule::requiredIf(! $porPromocion),
                'nullable',
                'numeric',
                'gt:0',
                function ($attribute, $value, $fail) {
                    if ($this->input('tipo_valor') === 'porcentaje' && (float) $value > 100) {
                        $fail('El porcentaje no puede ser mayor a 100.');
                    }
                },
            ],
            'motivo' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'motivo.required' => 'Escribe el motivo del descuento.',
            'motivo.min' => 'Escribe el motivo del descuento.',
            'valor.gt' => 'El descuento tiene que ser mayor a cero.',
            'valor.required' => 'Escribe el valor del descuento.',
            'tipo_valor.required' => 'Elige si el descuento es un porcentaje o un valor fijo.',
            'promocion_id.exists' => 'Esa promoción no se puede aplicar a mano.',
        ];
    }
}
