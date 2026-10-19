<?php

namespace App\Http\Requests\Api\Catalogo;

use App\Models\Producto;
use App\Services\PromocionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Mejora (16/10/2026): atajo «Poner en oferta». Precio de oferta (el sistema calcula la rebaja) o
 * porcentaje, hasta qué día (opcional) y a qué presentación (opcional; con precio de oferta y
 * presentaciones es obligatoria, porque cada una tiene su precio).
 */
class PonerOfertaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->esAdministrador() ?? false;
    }

    public function rules(): array
    {
        return [
            'modo' => ['required', 'in:precio,porcentaje'],
            'precio_oferta' => ['required_if:modo,precio', 'nullable', 'numeric', 'gt:0'],
            // RN-M04-23: mayor a cero; menor a 100 (al 100 % el producto sería gratis).
            'porcentaje' => ['required_if:modo,porcentaje', 'nullable', 'numeric', 'gt:0', 'lt:100'],
            'variante_id' => ['nullable', 'integer'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.PromocionService::enHoraDelNegocio()->toDateString()],
        ];
    }

    public function messages(): array
    {
        return [
            'modo.in' => 'Elige si la oferta es un precio o un porcentaje.',
            'precio_oferta.required_if' => 'Escribe el precio de oferta.',
            'precio_oferta.gt' => 'El precio de oferta debe ser mayor a cero.',
            'porcentaje.required_if' => 'Escribe el porcentaje de descuento.',
            'porcentaje.gt' => 'El porcentaje debe ser mayor a cero.',
            'porcentaje.lt' => 'El porcentaje debe ser menor a 100.',
            'hasta.after_or_equal' => 'La fecha límite no puede ser anterior a hoy.',
            'hasta.date_format' => 'La fecha límite no es válida.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            /** @var Producto $producto */
            $producto = $this->route('producto');
            $varianteId = $this->input('variante_id');
            $variante = $varianteId ? $producto->variantes()->where('activa', true)->find($varianteId) : null;
            if ($varianteId && ! $variante) {
                $validator->errors()->add('variante_id', 'Esa presentación no es de este producto o está desactivada.');

                return;
            }
            if ($this->input('modo') !== 'precio') {
                return;
            }
            if (! $variante && $producto->variantes()->where('activa', true)->exists()) {
                $validator->errors()->add('variante_id', 'Cada presentación tiene su precio: elige a cuál aplica el precio de oferta, o usa un porcentaje para todas.');

                return;
            }
            $referencia = (float) ($variante?->precio ?? $producto->precio_base);
            if ((float) $this->input('precio_oferta') >= $referencia) {
                $validator->errors()->add('precio_oferta', 'El precio de oferta debe ser menor que el precio normal ($'.number_format($referencia, 0, ',', '.').').');
            }
        }];
    }
}
