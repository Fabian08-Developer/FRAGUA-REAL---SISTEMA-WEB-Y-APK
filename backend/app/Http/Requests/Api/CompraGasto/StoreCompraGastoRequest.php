<?php

namespace App\Http\Requests\Api\CompraGasto;

use App\Services\CompraGastoService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * M03 — registrar una compra o un gasto (RF-M03-01 a 09, RN-M03-01 a 03, RN-M03-14).
 * Fase 2, tarea 4 (12/10/2026): la categoría es obligatoria y una de las de su tipo (M03,
 * sección 9); cantidad y unidad informativas; observaciones; soporte JPG, PNG o PDF validado
 * antes de guardarlo (RNF-M03-06); la fecha de la compra no puede ser futura. El enlace suelto
 * `soporte_url` ya no se recibe: el soporte se sube como archivo.
 */
class StoreCompraGastoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->esAdministrador() ?? false;
    }

    public function rules(): array
    {
        $categorias = CompraGastoService::categorias()[$this->input('tipo')] ?? [];

        return [
            'tipo' => ['required', 'in:compra,gasto'],
            'categoria' => ['required', 'string', Rule::in($categorias)],
            'descripcion' => ['required', 'string', 'max:255'],
            'cantidad' => ['nullable', 'numeric', 'gt:0', 'max:99999999'],
            'unidad' => ['nullable', 'string', 'max:50'],
            'valor' => ['required', 'numeric', 'min:0.01'], // RN-M03-01
            'metodo_pago' => ['required', 'in:efectivo,transferencia,tarjeta,otro'],
            'proveedor' => ['nullable', 'string', 'max:255'],
            'numero_factura' => ['nullable', 'string', 'max:255'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
            'fecha' => ['required', 'date', 'before_or_equal:'.now('America/Bogota')->toDateString()],
            'soporte' => self::reglasSoporte(),
            // Fase 2, tarea 5 (RN-M03-08): solo se piden si la fecha ya tiene cierre formal.
            'password_confirmacion' => ['nullable', 'string'],
            'motivo_correccion' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** RF-M03-07, RNF-M03-06: JPG, PNG o PDF, revisando el contenido real y no solo la extensión. */
    public static function reglasSoporte(): array
    {
        return ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'mimetypes:image/jpeg,image/png,application/pdf', 'max:'.config('fragua_real.soporte_max_kb')];
    }

    public static function mensajes(): array
    {
        return [
            'categoria.required' => 'Elige la categoría.',
            'categoria.in' => 'Elige una categoría de la lista para este tipo.',
            'valor.min' => 'El valor debe ser mayor a cero.',
            'cantidad.gt' => 'La cantidad debe ser mayor a cero.',
            'fecha.before_or_equal' => 'La fecha de la compra no puede ser futura.',
            'soporte.mimes' => 'El soporte debe ser una imagen JPG o PNG, o un PDF.',
            'soporte.mimetypes' => 'El soporte debe ser una imagen JPG o PNG, o un PDF.',
            'soporte.max' => 'El soporte es muy pesado (máximo '.round(config('fragua_real.soporte_max_kb') / 1024).' MB).',
        ];
    }

    public function messages(): array
    {
        return self::mensajes();
    }
}
