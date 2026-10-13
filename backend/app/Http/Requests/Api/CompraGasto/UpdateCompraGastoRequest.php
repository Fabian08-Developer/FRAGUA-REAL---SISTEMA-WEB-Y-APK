<?php

namespace App\Http\Requests\Api\CompraGasto;

use Illuminate\Foundation\Http\FormRequest;

// RF-M03-14: editar un registro de compra/gasto — todos los campos opcionales (se edita
// lo que se envíe); las mismas reglas de StoreCompraGastoRequest, con 'sometimes'.
class UpdateCompraGastoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->esAdministrador() ?? false;
    }

    public function rules(): array
    {
        return [
            'tipo' => ['sometimes', 'in:compra,gasto'],
            'categoria' => ['sometimes', 'nullable', 'string', 'max:255'],
            'descripcion' => ['sometimes', 'string', 'max:255'],
            'valor' => ['sometimes', 'numeric', 'min:0.01'], // RN-M03-01
            'metodo_pago' => ['sometimes', 'in:efectivo,transferencia,tarjeta,otro'],
            'proveedor' => ['sometimes', 'nullable', 'string', 'max:255'],
            'numero_factura' => ['sometimes', 'nullable', 'string', 'max:255'],
            'soporte_url' => ['sometimes', 'nullable', 'string', 'max:255'],
            'fecha' => ['sometimes', 'date'],
        ];
    }
}
