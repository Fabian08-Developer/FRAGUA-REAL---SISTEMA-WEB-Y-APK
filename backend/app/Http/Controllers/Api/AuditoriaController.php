<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auditoria\ConsultarAuditoriaRequest;
use App\Services\AuditoriaConsultaService;

/**
 * M01 (RF-M01-025, HU-M01-020), M00 (RNF-TRANS-023) — Fase 3, tarea 1 (12/10/2026).
 *
 * Consulta del historial de auditoría, exclusiva del Administrador (role:administrador en
 * routes/api.php). Solo lectura: no hay rutas para editar ni borrar registros (CA-M01-020.2,
 * RN-USR-13).
 */
class AuditoriaController extends Controller
{
    public function __construct(private readonly AuditoriaConsultaService $consulta)
    {
    }

    // GET /auditorias?desde=&hasta=&user_id=&categoria=&accion=&entidad=&entidad_id=&ip=&busqueda=&page=
    public function index(ConsultarAuditoriaRequest $request)
    {
        return response()->json($this->consulta->consultar($request->validated()));
    }

    // GET /auditorias/opciones: valores para los filtros y resumen de eventos de seguridad.
    public function opciones()
    {
        return response()->json($this->consulta->opciones());
    }
}
