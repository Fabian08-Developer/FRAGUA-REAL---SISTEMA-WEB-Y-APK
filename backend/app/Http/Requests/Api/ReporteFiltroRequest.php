<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

// RF-M08-001, RN-M08-03: periodo semana/mes/personalizado (semana actual si no se elige
// ninguno). RF-M08-010: 'comparar' activa la comparación contra el periodo anterior.
class ReporteFiltroRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->esAdministrador() ?? false; // RF-M08-013: exclusivo del Administrador.
    }

    public function rules(): array
    {
        return [
            'periodo' => ['nullable', 'in:semana,mes,personalizado'],
            'desde' => ['required_if:periodo,personalizado', 'date'],
            'hasta' => ['required_if:periodo,personalizado', 'date', 'after_or_equal:desde'],
            'comparar' => ['nullable', 'boolean'],
            'limite' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function periodoElegido(): string
    {
        return $this->input('periodo', 'semana'); // RN-M08-03
    }
}
