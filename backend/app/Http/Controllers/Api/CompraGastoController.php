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

    public function index(Request $request)
    {
        abort_if(! $request->user()->esAdministrador(), 403);

        // HU-M03-03: la tabla debe mostrar también quién registró cada compra/gasto — antes
        // faltaba el eager load y ese dato nunca llegaba al frontend (mismo patrón que
        // CajaController::index() con 'abiertoPorUsuario'/'cerradoPorUsuario').
        return response()->json(
            CompraGasto::with('registradoPorUsuario')
                ->when($request->tipo, fn ($q) => $q->where('tipo', $request->tipo))
                ->latest('fecha')
                ->paginate(20)
        );
    }

    public function store(StoreCompraGastoRequest $request)
    {
        return response()->json($this->compraGastoService->registrar($request->validated(), $request->user()), 201);
    }

    // RF-M03-14: editar un registro activo (los campos que se envíen; ver
    // UpdateCompraGastoRequest, todos opcionales).
    public function update(UpdateCompraGastoRequest $request, CompraGasto $compraGasto)
    {
        return response()->json($this->compraGastoService->editar($compraGasto, $request->validated()));
    }

    public function anular(AnularCompraGastoRequest $request, CompraGasto $compraGasto)
    {
        return response()->json($this->compraGastoService->anular($compraGasto, $request->motivo));
    }

    public function generarCierreDiario(Request $request)
    {
        abort_if(! $request->user()->esAdministrador(), 403);
        $request->validate(['fecha' => ['required', 'date']]);

        return response()->json($this->cierreDiarioService->generar(new \DateTime($request->fecha), $request->user()), 201);
    }
}
