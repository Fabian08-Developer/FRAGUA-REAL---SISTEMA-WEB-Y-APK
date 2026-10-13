<?php

namespace App\Http\Requests\Api\CompraGasto;

use Illuminate\Foundation\Http\FormRequest;

class StoreCompraGastoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->esAdministrador() ?? false;
    }

    public function rules(): array
    {
        return [
            'tipo' => ['required', 'in:compra,gasto'],
            'categoria' => ['nullable', 'string', 'max:255'],
            'descripcion' => ['required', 'string', 'max:255'],
            'valor' => ['required', 'numeric', 'min:0.01'], // RN-M03-01
            'metodo_pago' => ['required', 'in:efectivo,transferencia,tarjeta,otro'],
            'proveedor' => ['nullable', 'string', 'max:255'],
            'numero_factura' => ['nullable', 'string', 'max:255'],
            'soporte_url' => ['nullable', 'string', 'max:255'],
            'fecha' => ['required', 'date'],
        ];
    }
}
