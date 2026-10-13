<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CompraGasto\AnularCompraGastoRequest;
use App\Http\Requests\Api\CompraGasto\StoreCompraGastoRequest;
use App\Http\Requests\Api\CompraGasto\UpdateCompraGastoRequest;
use App\Models\CompraGasto;
use App\Services\CierreDiarioService;
use App\Services\CompraGastoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * M03 — Compras, Gastos y Cierre Diario. Exclusivo del Administrador (control financiero).
 */
class CompraGastoController extends Controller
{
    public function __construct(
        private readonly CompraGastoService $compraGastoService,
        private readonly CierreDiarioService $cierreDiarioService,
    ) {
    }

    // HU-M03-03, RF-M03-12/13 (Fase 2, tarea 4): historial con filtros por fecha (desde/hasta),
    // tipo, categoría, forma de pago, proveedor y estado, y los totales de lo filtrado (solo
    // activos, RN-M03-04). Muestra quién registró, corrigió y anuló cada registro.
    public function index(Request $request)
    {
        abort_if(! $request->user()->esAdministrador(), 403);
        $filtros = $request->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
            'tipo' => ['nullable', 'in:compra,gasto'],
            'categoria' => ['nullable', 'string', 'max:255'],
            'metodo_pago' => ['nullable', 'in:efectivo,transferencia,tarjeta,otro'],
            'proveedor' => ['nullable', 'string', 'max:255'],
            'estado' => ['nullable', 'in:activo,anulado'],
        ]);

        $consulta = $this->compraGastoService->consulta($filtros);
        $pagina = (clone $consulta)
            ->with(['registradoPorUsuario', 'editadoPorUsuario', 'anuladoPorUsuario'])
            ->latest('fecha')->latest('id')
            ->paginate(20);

        return response()->json(array_merge($pagina->toArray(), [
            'totales' => $this->compraGastoService->totales($consulta),
        ]));
    }

    // Categorías V1 por tipo (M03, sección 9), para los selectores del formulario y los filtros.
    public function categorias(Request $request)
    {
        abort_if(! $request->user()->esAdministrador(), 403);

        return response()->json(CompraGastoService::categorias());
    }

    public function store(StoreCompraGastoRequest $request)
    {
        $data = $request->safe()->except('soporte');

        return response()->json($this->compraGastoService->registrar($data, $request->user(), $request->file('soporte')), 201);
    }

    // RF-M03-14: editar un registro activo (los campos que se envíen; ver
    // UpdateCompraGastoRequest, todos opcionales).
    public function update(UpdateCompraGastoRequest $request, CompraGasto $compraGasto)
    {
        return response()->json($this->compraGastoService->editar($compraGasto, $request->validated(), $request->user()));
    }

    public function anular(AnularCompraGastoRequest $request, CompraGasto $compraGasto)
    {
        return response()->json($this->compraGastoService->anular($compraGasto, $request->motivo, $request->user()));
    }

    // RF-M03-07, HU-M03-02: adjuntar o reemplazar el soporte (multipart, campo "soporte").
    public function adjuntarSoporte(Request $request, CompraGasto $compraGasto)
    {
        abort_if(! $request->user()->esAdministrador(), 403);
        $request->validate(['soporte' => ['required', ...array_slice(StoreCompraGastoRequest::reglasSoporte(), 1)]], StoreCompraGastoRequest::mensajes());

        return response()->json($this->compraGastoService->adjuntarSoporte($compraGasto, $request->file('soporte'), $request->user()));
    }

    // RNF-M03-11: el soporte solo se ve con sesión de Administrador; no hay enlace público.
    public function verSoporte(Request $request, CompraGasto $compraGasto)
    {
        abort_if(! $request->user()->esAdministrador(), 403);
        abort_if(! $this->compraGastoService->existeSoporte($compraGasto), 404, 'Este registro no tiene soporte.');

        return Storage::disk(CompraGastoService::DISCO_SOPORTES)->response(
            $compraGasto->soporte_path,
            $compraGasto->soporte_nombre ?? basename($compraGasto->soporte_path),
            ['Content-Type' => $compraGasto->soporte_mime ?? 'application/octet-stream', 'Cache-Control' => 'private, no-store'],
            'inline',
        );
    }

    public function generarCierreDiario(Request $request)
    {
        abort_if(! $request->user()->esAdministrador(), 403);
        $request->validate(['fecha' => ['required', 'date']]);

        return response()->json($this->cierreDiarioService->generar(new \DateTime($request->fecha), $request->user()), 201);
    }
}
