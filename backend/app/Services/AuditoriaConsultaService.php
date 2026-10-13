<?php

namespace App\Services;

use App\Models\Auditoria;
use App\Models\User;
use App\Support\Auditoria\CatalogoAuditoria;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * M01 (RF-M01-025, HU-M01-020, CA-M01-020.1/020.2), M00 (RNF-TRANS-023) — Fase 3, tarea 1
 * (12/10/2026).
 *
 * Consulta de solo lectura del historial de auditoría para el Administrador: filtros por
 * fecha (días del negocio), usuario, grupo, acción, entidad, IP y texto; las opciones de
 * esos filtros; y un resumen de eventos de seguridad (intentos de inicio de sesión fallidos
 * de las últimas 24 horas). Nada de aquí escribe en `auditorias` (CA-M01-020.2): el
 * historial solo crece con AuditoriaService::registrar() y la base de datos rechaza
 * cualquier cambio o borrado (migración 2026_10_17_000001).
 */
class AuditoriaConsultaService
{
    private const ZONA_HORARIA_NEGOCIO = 'America/Bogota';

    public function consultar(array $filtros): LengthAwarePaginator
    {
        $pagina = $this->filtrar(Auditoria::query()->with('user:id,nombre,email,role'), $filtros)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) ($filtros['por_pagina'] ?? 50));

        return $pagina->through(fn (Auditoria $registro) => $this->presentar($registro));
    }

    /** Opciones de los filtros: grupos, acciones, entidades y usuarios que aparecen en el historial. */
    public function opciones(): array
    {
        $accionesUsadas = Auditoria::query()->distinct()->pluck('accion')->all();
        $acciones = collect(array_keys(CatalogoAuditoria::ACCIONES))
            ->merge($accionesUsadas)
            ->unique()
            ->map(fn (string $accion) => [
                'valor' => $accion,
                'texto' => CatalogoAuditoria::texto($accion),
                'categoria' => CatalogoAuditoria::categoria($accion),
            ])
            ->sortBy('texto', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        $entidades = collect(array_keys(CatalogoAuditoria::ENTIDADES))
            ->merge(Auditoria::query()->distinct()->pluck('entidad')->all())
            ->unique()
            ->map(fn (string $entidad) => ['valor' => $entidad, 'texto' => CatalogoAuditoria::entidad($entidad)])
            ->sortBy('texto', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        $usuarios = User::query()
            ->whereIn('id', Auditoria::query()->whereNotNull('user_id')->distinct()->select('user_id'))
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'email', 'role']);

        return [
            'categorias' => collect(CatalogoAuditoria::CATEGORIAS)
                ->map(fn (string $texto, string $valor) => ['valor' => $valor, 'texto' => $texto])
                ->values(),
            'acciones' => $acciones,
            'entidades' => $entidades,
            'usuarios' => $usuarios,
            'seguridad' => $this->resumenSeguridad(),
        ];
    }

    /**
     * Eventos de seguridad de las últimas 24 horas (RF-M01-017/024): cuántos intentos de inicio
     * de sesión fallaron y desde qué IP o con qué correo se repitieron más (posible ataque de
     * contraseñas).
     */
    public function resumenSeguridad(): array
    {
        $desde = now()->subDay();
        $fallidos = Auditoria::query()
            ->where('accion', 'inicio_sesion_fallido')
            ->where('created_at', '>=', $desde);

        $porIp = (clone $fallidos)->whereNotNull('ip')
            ->select('ip', DB::raw('COUNT(*) as intentos'))
            ->groupBy('ip')
            ->havingRaw('COUNT(*) >= 3')
            ->orderByDesc('intentos')
            ->limit(5)
            ->get()
            ->map(fn ($fila) => ['ip' => $fila->ip, 'intentos' => (int) $fila->intentos]);

        return [
            'desde' => $desde->toIso8601String(),
            'inicios_fallidos_24h' => (clone $fallidos)->count(),
            'inicios_exitosos_24h' => Auditoria::query()->where('accion', 'inicio_sesion')->where('created_at', '>=', $desde)->count(),
            'restablecimientos_24h' => Auditoria::query()->where('accion', 'restablecer_contrasena')->where('created_at', '>=', $desde)->count(),
            'ips_con_intentos_repetidos' => $porIp,
        ];
    }

    private function filtrar(Builder $consulta, array $filtros): Builder
    {
        if (! empty($filtros['desde'])) {
            $consulta->where('created_at', '>=', Carbon::parse($filtros['desde'], self::ZONA_HORARIA_NEGOCIO)->startOfDay()->utc());
        }
        if (! empty($filtros['hasta'])) {
            $consulta->where('created_at', '<=', Carbon::parse($filtros['hasta'], self::ZONA_HORARIA_NEGOCIO)->endOfDay()->utc());
        }
        if (! empty($filtros['user_id'])) {
            $consulta->where('user_id', (int) $filtros['user_id']);
        }
        if (! empty($filtros['categoria'])) {
            $conocidas = array_keys(CatalogoAuditoria::ACCIONES);
            $filtros['categoria'] === 'otros'
                ? $consulta->whereNotIn('accion', $conocidas)
                : $consulta->whereIn('accion', CatalogoAuditoria::accionesDe($filtros['categoria']));
        }
        if (! empty($filtros['accion'])) {
            $consulta->where('accion', $filtros['accion']);
        }
        if (! empty($filtros['entidad'])) {
            $consulta->where('entidad', $filtros['entidad']);
        }
        if (! empty($filtros['entidad_id'])) {
            $consulta->where('entidad_id', (int) $filtros['entidad_id']);
        }
        if (! empty($filtros['ip'])) {
            $consulta->where('ip', trim($filtros['ip']));
        }
        if (! empty($filtros['busqueda'])) {
            $this->buscarTexto($consulta, trim($filtros['busqueda']));
        }

        return $consulta;
    }

    // Busca en los detalles (motivo, correo, valores antes y después) y en el navegador. Los
    // detalles se guardan como JSON, donde las tildes pueden quedar escapadas (ó): se
    // busca el texto tal cual y también en esa forma.
    private function buscarTexto(Builder $consulta, string $texto): void
    {
        $operador = DB::connection()->getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';
        // Los comodines (% y _) se escapan con "!" y no con barra invertida: con la barra, PDO de
        // PostgreSQL confunde el final de ESCAPE con una comilla escapada y pierde el parámetro.
        $escapar = fn (string $t) => '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $t).'%';
        $variantes = array_unique([$texto, trim(json_encode($texto), '"')]);

        $consulta->where(function (Builder $q) use ($variantes, $operador, $escapar) {
            foreach ($variantes as $variante) {
                $q->orWhereRaw("CAST(detalles AS TEXT) {$operador} ? ESCAPE '!'", [$escapar($variante)])
                    ->orWhereRaw("user_agent {$operador} ? ESCAPE '!'", [$escapar($variante)]);
            }
        });
    }

    public function presentar(Auditoria $registro): array
    {
        return [
            'id' => $registro->id,
            'created_at' => $registro->created_at?->toIso8601String(),
            'accion' => $registro->accion,
            'accion_texto' => CatalogoAuditoria::texto($registro->accion),
            'categoria' => CatalogoAuditoria::categoria($registro->accion),
            'categoria_texto' => CatalogoAuditoria::CATEGORIAS[CatalogoAuditoria::categoria($registro->accion)],
            'entidad' => $registro->entidad,
            'entidad_texto' => CatalogoAuditoria::entidad($registro->entidad),
            'entidad_id' => $registro->entidad_id,
            'detalles' => $registro->detalles,
            'ip' => $registro->ip,
            'user_agent' => $registro->user_agent,
            'navegador' => $this->describirNavegador($registro->user_agent),
            'usuario' => $registro->user ? [
                'id' => $registro->user->id,
                'nombre' => $registro->user->nombre,
                'email' => $registro->user->email,
                'role' => $registro->user->role,
            ] : null,
        ];
    }

    // Texto corto y legible del navegador ("Chrome en Android"); el texto completo sigue en user_agent.
    public function describirNavegador(?string $userAgent): ?string
    {
        if (! $userAgent) {
            return null;
        }

        $navegador = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/') || str_contains($userAgent, 'Opera') => 'Opera',
            str_contains($userAgent, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') || str_contains($userAgent, 'CriOS/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => null,
        };

        $sistema = match (true) {
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Mac OS X') || str_contains($userAgent, 'Macintosh') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        if (! $navegador && ! $sistema) {
            return 'Otro cliente';
        }

        return trim(($navegador ?? 'Navegador').($sistema ? " en {$sistema}" : ''));
    }
}
