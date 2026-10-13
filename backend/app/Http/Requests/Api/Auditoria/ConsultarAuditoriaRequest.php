<?php

namespace App\Http\Requests\Api\Auditoria;

use App\Support\Auditoria\CatalogoAuditoria;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * RF-M01-025, HU-M01-020 (Fase 3, tarea 1): filtros de la consulta de auditoría. Las fechas
 * son días del negocio (hora de Colombia); todos los filtros son opcionales.
 */
class ConsultarAuditoriaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // la ruta ya exige role:administrador (RNF-TRANS-023)
    }

    public function rules(): array
    {
        return [
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'categoria' => ['nullable', Rule::in(array_keys(CatalogoAuditoria::CATEGORIAS))],
            'accion' => ['nullable', 'string', 'max:60'],
            'entidad' => ['nullable', 'string', 'max:60'],
            'entidad_id' => ['nullable', 'integer', 'min:1'],
            'ip' => ['nullable', 'string', 'max:45'],
            'busqueda' => ['nullable', 'string', 'max:100'],
            'por_pagina' => ['nullable', 'integer', 'min:10', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'hasta.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.',
            'categoria.in' => 'Ese grupo de acciones no existe.',
        ];
    }
}
