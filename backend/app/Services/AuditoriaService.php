<?php

namespace App\Services;

use App\Models\Auditoria;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * M00 (RNF-TRANS-020/021/022), M01 (RF-M01-017, RF-M01-024) — Fase 0, tarea 7 (09/10/2026).
 *
 * Único punto para dejar constancia de una acción crítica: quién, qué, sobre qué registro,
 * cuándo, desde qué IP y con qué navegador, más los detalles propios de la acción (motivo,
 * valores antes y después). Antes cada módulo escribía en `auditorias` por su cuenta, y casi
 * ninguno lo hacía. Los registros no se pueden editar ni borrar (ver Auditoria::booted).
 *
 * Uso: app(AuditoriaService::class)->registrar('anular_pago', 'Pago', $pago->id, [...], $actor);
 */
class AuditoriaService
{
    public function registrar(string $accion, string $entidad, ?int $entidadId = null, array $detalles = [], ?User $actor = null): Auditoria
    {
        $request = app()->bound('request') ? request() : null;

        return Auditoria::create([
            'user_id' => $actor?->id ?? Auth::id(),
            'accion' => $accion,
            'entidad' => $entidad,
            'entidad_id' => $entidadId,
            'detalles' => $detalles ?: null,
            'ip' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
        ]);
    }
}
