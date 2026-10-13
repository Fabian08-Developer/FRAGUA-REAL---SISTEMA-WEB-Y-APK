<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

// RF-M08-011/012: exportar uno de los 6 reportes de M08 ya calculado, a PDF o a Excel.
class ReporteExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->esAdministrador() ?? false; // RF-M08-013: exclusivo del Administrador.
    }

    public function rules(): array
    {
        return [
            'reporte' => ['required', 'in:ventas-totales,ventas-por-producto,ventas-por-tipo-y-metodo,compras-y-gastos,resultado-operativo,uso-de-mesas'],
            'formato' => ['required', 'in:pdf,excel'],
            'periodo' => ['nullable', 'in:semana,mes,personalizado'],
            'desde' => ['required_if:periodo,personalizado', 'date'],
            'hasta' => ['required_if:periodo,personalizado', 'date', 'after_or_equal:desde'],
            // Fase 5, tarea 3 (CA-M08-009/010): el archivo lleva lo mismo que la pantalla, también la
            // comparación con el periodo anterior y el tope de más/menos vendidos.
            'comparar' => ['nullable', 'boolean'],
            'limite' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function periodoElegido(): string
    {
        return $this->input('periodo', 'semana'); // RN-M08-03
    }
}
