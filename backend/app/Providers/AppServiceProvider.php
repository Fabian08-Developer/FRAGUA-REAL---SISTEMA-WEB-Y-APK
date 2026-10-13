<?php

namespace App\Providers;

use App\Services\SesionService;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Fragua Real Burger es una aplicación 100% en español (ver lang/es/validation.php).
        // El .env del proyecto trae APP_LOCALE=en de la plantilla por defecto de Laravel y
        // ese archivo no se puede editar por control remoto — se fuerza aquí para que los
        // mensajes de validación autogenerados de los Form Requests (ej. "El campo nombre
        // es obligatorio.") salgan en español sin depender de ese valor del .env.
        config(['app.locale' => 'es', 'app.fallback_locale' => 'es']);

        // Fase 3, tarea 4 (RF-M01-010, RNF-M01-005): además de su vencimiento máximo
        // (expires_at), una sesión deja de servir si lleva más del tiempo de inactividad sin
        // usarse (config/fragua_real.php, "sesiones"). Sanctum responde 401 y la app vuelve al login.
        Sanctum::authenticateAccessTokensUsing(
            fn (PersonalAccessToken $token, bool $esValida) => $esValida && app(SesionService::class)->sigueVigente($token)
        );
    }
}
