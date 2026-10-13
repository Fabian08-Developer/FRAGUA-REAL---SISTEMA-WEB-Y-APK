<?php

namespace App\Services;

use App\Models\User;
use App\Support\Navegador;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * M01 (RF-M01-010, RF-M01-023, RF-M01-024, HU-M01-010, RNF-M01-005) — Fase 3, tarea 4
 * (12/10/2026).
 *
 * Una "sesión" es un token de Sanctum. Este servicio:
 *  - la abre guardando su origen (IP y navegador) y su vencimiento máximo;
 *  - decide si sigue vigente: no pasó su duración máxima y no lleva más del tiempo de
 *    inactividad sin usarse (config/fragua_real.php, "sesiones"); AppServiceProvider le pasa
 *    esta regla a Sanctum, así que una sesión vencida responde 401 y la app vuelve al login;
 *  - lista las sesiones vigentes de una persona y cierra una en particular o todas menos la
 *    actual; y borra de la base las vencidas (comando `sesiones:limpiar`, programado a diario).
 */
class SesionService
{
    public function abrir(User $usuario, Request $request, string $nombre = 'acceso'): array
    {
        $vence = now()->addMinutes($this->duracionMaxima());
        $nuevo = $usuario->createToken($nombre, ['*'], $vence);
        $nuevo->accessToken->forceFill([
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255) ?: null,
        ])->save();

        return ['token' => $nuevo->plainTextToken, 'sesion' => $nuevo->accessToken];
    }

    /** Regla de vigencia que usa Sanctum en cada petición (además de expires_at). */
    public function sigueVigente(PersonalAccessToken $token): bool
    {
        $ultimoUso = $token->last_used_at ?? $token->created_at;

        return $ultimoUso === null || $ultimoUso->gt(now()->subMinutes($this->inactividad()));
    }

    /** Sesiones vigentes: sin vencer y usadas dentro del tiempo de inactividad. */
    public function vigentes(Builder $consulta): Builder
    {
        $limite = now()->subMinutes($this->inactividad());

        return $consulta
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn (Builder $q) => $q->where('last_used_at', '>=', $limite)
                ->orWhere(fn (Builder $q2) => $q2->whereNull('last_used_at')->where('created_at', '>=', $limite)));
    }

    /** @return list<array<string, mixed>> */
    public function listar(User $usuario, ?PersonalAccessToken $actual = null): array
    {
        return $this->vigentes(PersonalAccessToken::query()
            ->where('tokenable_type', $usuario->getMorphClass())
            ->where('tokenable_id', $usuario->getKey()))
            ->orderByRaw('COALESCE(last_used_at, created_at) DESC')
            ->get()
            ->map(fn (PersonalAccessToken $token) => $this->presentar($token, $actual))
            ->values()
            ->all();
    }

    public function contarVigentes(User $usuario): int
    {
        return $this->vigentes($usuario->tokens()->getQuery())->count();
    }

    public function presentar(PersonalAccessToken $token, ?PersonalAccessToken $actual = null): array
    {
        $ultimoUso = $token->last_used_at ?? $token->created_at;
        $porInactividad = $ultimoUso?->copy()->addMinutes($this->inactividad());
        $vence = $token->expires_at && $porInactividad
            ? ($token->expires_at->lt($porInactividad) ? $token->expires_at : $porInactividad)
            : ($token->expires_at ?? $porInactividad);

        return [
            'id' => $token->id,
            'dispositivo' => Navegador::describir($token->user_agent) ?? 'Sin datos del dispositivo',
            'ip' => $token->ip,
            'user_agent' => $token->user_agent,
            'creada_at' => $token->created_at?->toIso8601String(),
            'ultimo_uso_at' => $token->last_used_at?->toIso8601String(),
            'vence_at' => $vence?->toIso8601String(),
            'actual' => $actual !== null && $actual->getKey() === $token->getKey(),
        ];
    }

    /** Cierra una sesión concreta de esa persona. Devuelve null si no existe o no es suya. */
    public function cerrar(User $usuario, int $sesionId): ?array
    {
        $token = $usuario->tokens()->whereKey($sesionId)->first();
        if (! $token) {
            return null;
        }
        $datos = $this->presentar($token);
        $token->delete();

        return $datos;
    }

    /** Cierra todas las sesiones de la persona salvo la actual. Devuelve cuántas cerró. */
    public function cerrarOtras(User $usuario, ?PersonalAccessToken $actual): int
    {
        return $usuario->tokens()
            ->when($actual, fn ($q) => $q->whereKeyNot($actual->getKey()))
            ->delete();
    }

    /** Borra de la base las sesiones vencidas o abandonadas. Devuelve cuántas borró. */
    public function limpiarVencidas(): int
    {
        $limite = now()->subMinutes($this->inactividad());

        return PersonalAccessToken::query()
            ->where(fn (Builder $q) => $q->whereNotNull('expires_at')->where('expires_at', '<=', now()))
            ->orWhere('last_used_at', '<', $limite)
            ->orWhere(fn (Builder $q) => $q->whereNull('last_used_at')->where('created_at', '<', $limite))
            ->delete();
    }

    public function inactividad(): int
    {
        return max(1, (int) config('fragua_real.sesiones.inactividad_minutos', 60 * 24 * 7));
    }

    public function duracionMaxima(): int
    {
        return max(1, (int) config('fragua_real.sesiones.duracion_maxima_minutos', 60 * 24 * 30));
    }

    /** Para mostrar la política en pantalla. */
    public function politica(): array
    {
        return [
            'inactividad_minutos' => $this->inactividad(),
            'duracion_maxima_minutos' => $this->duracionMaxima(),
        ];
    }
}
