<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

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
    }
}
