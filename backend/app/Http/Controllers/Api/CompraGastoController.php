<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CompraGasto\AnularCompraGastoRequest;
use App\Http\Requests\Api\CompraGasto\StoreCompraGastoRequest;
use App\Http\Requests\Api\CompraGasto\UpdateCompraGastoRequest;
use App\Models\CierreDiario;
use App\Models\CompraGasto;
use App\Services\CierreDiarioService;
use App\Services\CompraGastoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * M03 — Compras, Gastos y Cierre Diario. Del Administrador (control financiero).
 *
 * Fase 7, tarea 2 (RNF-TRANS-012, RF-M03-32, HU-M03-01 a 03): un Mesero con el permiso
 * `puede_registrar_gastos` registra compras y gastos, adjunta su soporte y consulta lo que él
 * mismo registró. Editar, anular, los totales de todo el negocio y el cierre diario siguen siendo
 * solo del Administrador.
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
        $usuario = $request->user();
        abort_if(! $usuario->puedeRegistrarComprasGastos(), 403);
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
        // El Mesero autorizado solo ve lo que él registró (y sus totales).
        if (! $usuario->esAdministrador()) {
            $consulta->where('registrado_por', $usuario->id);
        }
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
        abort_if(! $request->user()->puedeRegistrarComprasGastos(), 403);

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
        return response()->json($this->compraGastoService->anular($compraGasto, $request->motivo, $request->user(), $request->input('password_confirmacion')));
    }

    // RF-M03-07, HU-M03-02: adjuntar o reemplazar el soporte (multipart, campo "soporte").
    public function adjuntarSoporte(Request $request, CompraGasto $compraGasto)
    {
        $this->autorizarRegistro($request, $compraGasto);
        // El Mesero autorizado solo completa sus registros activos; reemplazar el soporte de uno
        // anulado o de otro es cosa del Administrador.
        abort_if(! $request->user()->esAdministrador() && ! $compraGasto->estaActivo(), 422, 'Este registro está anulado.');
        $request->validate(['soporte' => ['required', ...array_slice(StoreCompraGastoRequest::reglasSoporte(), 1)]], StoreCompraGastoRequest::mensajes());

        return response()->json($this->compraGastoService->adjuntarSoporte($compraGasto, $request->file('soporte'), $request->user()));
    }

    // RNF-M03-11: el soporte solo se ve con sesión (el Administrador, o el Mesero autorizado que
    // lo registró); no hay enlace público.
    public function verSoporte(Request $request, CompraGasto $compraGasto)
    {
        $this->autorizarRegistro($request, $compraGasto);
        abort_if(! $this->compraGastoService->existeSoporte($compraGasto), 404, 'Este registro no tiene soporte.');

        return Storage::disk(CompraGastoService::DISCO_SOPORTES)->response(
            $compraGasto->soporte_path,
            $compraGasto->soporte_nombre ?? basename($compraGasto->soporte_path),
            ['Content-Type' => $compraGasto->soporte_mime ?? 'application/octet-stream', 'Cache-Control' => 'private, no-store'],
            'inline',
        );
    }

    // RF-M03-25, HU-M03-08: cierre formal (solo Administrador). Exige la caja del día cerrada.
    public function generarCierreDiario(Request $request)
    {
        abort_if(! $request->user()->esAdministrador(), 403);
        $request->validate(['fecha' => ['required', 'date']]);

        $cierre = $this->cierreDiarioService->generar($request->string('fecha')->toString(), $request->user());

        return response()->json($this->cierreDiarioService->consulta($cierre), 201);
    }

    // ---- Fase 2, tarea 5: consulta previa e historial de cierres ----

    // RF-M03-26: cálculo actualizado del día antes del cierre formal (o el de un día ya cerrado).
    public function previaCierre(Request $request)
    {
        abort_if(! $request->user()->esAdministrador(), 403);
        $request->validate(['fecha' => ['required', 'date']]);

        return response()->json($this->cierreDiarioService->previa($request->string('fecha')->toString()));
    }

    // RF-M03-27: historial de cierres realizados, del más reciente al más antiguo.
    public function cierres(Request $request)
    {
        abort_if(! $request->user()->esAdministrador(), 403);

        return response()->json(
            CierreDiario::with('cerradoPorUsuario:id,nombre')->withCount('ajustes')
                ->latest('fecha')->paginate(20)
        );
    }

    // Fase 5, tarea 2 (RF-M03-28/29, HU-M03-11/12): el cierre en PDF para imprimir o archivar.
    // Cada impresión queda en la auditoría; desde la segunda sale marcado "REIMPRESIÓN".
    public function pdfCierre(Request $request, CierreDiario $cierre)
    {
        abort_if(! $request->user()->esAdministrador(), 403);

        return $this->cierreDiarioService->pdf($cierre, $request->user());
    }

    // RN-M03-15, HU-M03-09: un cierre tal como se hizo, sus ajustes posteriores y los valores actualizados.
    public function verCierre(Request $request, CierreDiario $cierre)
    {
        abort_if(! $request->user()->esAdministrador(), 403);

        return response()->json($this->cierreDiarioService->consulta($cierre));
    }

    /** El Administrador ve todo; el Mesero autorizado, solo lo que registró él (404 lo demás). */
    private function autorizarRegistro(Request $request, CompraGasto $compraGasto): void
    {
        $usuario = $request->user();
        abort_if(! $usuario->puedeRegistrarComprasGastos(), 403);
        abort_if(! $usuario->esAdministrador() && (int) $compraGasto->registrado_por !== (int) $usuario->id, 404);
    }
}
