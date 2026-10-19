<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ImportacionProductosService;
use Illuminate\Http\Request;

/**
 * Mejora (16/10/2026): importación masiva de productos (ver ImportacionProductosService). Solo el
 * Administrador (RN-M04-01). La vista previa no guarda nada; la importación vuelve a revisar el mismo
 * archivo y solo guarda si no tiene errores.
 */
class ImportacionProductosController extends Controller
{
    public function __construct(private readonly ImportacionProductosService $importacion)
    {
    }

    public function plantilla(Request $request)
    {
        abort_if(! $request->user()->esAdministrador(), 403);

        return response($this->importacion->plantilla(), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="plantilla-productos-fragua-real.xlsx"',
        ]);
    }

    public function vistaPrevia(Request $request)
    {
        abort_if(! $request->user()->esAdministrador(), 403);
        $this->validarArchivo($request);

        return response()->json($this->importacion->analizar($request->file('archivo'), $request->boolean('actualizar', true)));
    }

    public function importar(Request $request)
    {
        abort_if(! $request->user()->esAdministrador(), 403);
        $this->validarArchivo($request);

        return response()->json($this->importacion->importar($request->file('archivo'), $request->boolean('actualizar', true), $request->user()));
    }

    private function validarArchivo(Request $request): void
    {
        $request->validate([
            'archivo' => ['required', 'file', 'extensions:xlsx,xls,csv', 'max:2048'],
            'actualizar' => ['nullable', 'boolean'],
        ], [
            'archivo.required' => 'Elige el archivo de Excel con los productos.',
            'archivo.extensions' => 'El archivo debe ser de Excel (.xlsx o .xls) o CSV.',
            'archivo.max' => 'El archivo es muy pesado (máximo 2 MB).',
        ]);
    }
}
