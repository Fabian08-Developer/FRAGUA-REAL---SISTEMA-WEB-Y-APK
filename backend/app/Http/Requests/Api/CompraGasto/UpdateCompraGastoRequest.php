<?php

namespace App\Http\Requests\Api\CompraGasto;

use App\Services\CompraGastoService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * RF-M03-14: corregir un registro activo; solo se envían los campos que cambian.
 * Fase 2, tarea 4: la categoría tiene que corresponder al tipo (el nuevo, si se cambia). Un
 * registro viejo con una categoría fuera de la lista se puede seguir editando sin tocarla.
 * El soporte se adjunta aparte (POST /compras-gastos/{id}/soporte).
 */
class UpdateCompraGastoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->esAdministrador() ?? false;
    }

    public function rules(): array
    {
        $registro = $this->route('compraGasto');

        return [
            'tipo' => ['sometimes', 'in:compra,gasto'],
            'categoria' => ['sometimes', 'required', 'string', function ($attribute, $value, $fail) use ($registro) {
                $tipo = $this->input('tipo', $registro?->tipo);
                $sinCambio = $registro && $value === $registro->categoria && $tipo === $registro->tipo;
                if (! $sinCambio && ! in_array($value, CompraGastoService::categorias()[$tipo] ?? [], true)) {
                    $fail('Elige una categoría de la lista para este tipo.');
                }
            }],
            'descripcion' => ['sometimes', 'string', 'max:255'],
            'cantidad' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:99999999'],
            'unidad' => ['sometimes', 'nullable', 'string', 'max:50'],
            'valor' => ['sometimes', 'numeric', 'min:0.01', 'max:'.\App\Support\Validacion\Formatos::MAX_PESOS], // RN-M03-01
            'metodo_pago' => ['sometimes', 'in:efectivo,transferencia,tarjeta,otro'],
            'proveedor' => ['sometimes', 'nullable', 'string', 'max:255'],
            'numero_factura' => ['sometimes', 'nullable', 'string', 'max:255'],
            'observaciones' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'fecha' => ['sometimes', 'date', 'before_or_equal:'.now('America/Bogota')->toDateString()],
            // Fase 2, tarea 5 (RN-M03-08): solo se piden si el día ya tiene cierre formal.
            'password_confirmacion' => ['nullable', 'string'],
            'motivo_correccion' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return StoreCompraGastoRequest::mensajes();
    }
}
