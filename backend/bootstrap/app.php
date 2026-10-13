<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureUserHasRole::class,
            'activo' => \App\Http\Middleware\EnsureUserIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // CORREGIDO (29/09/2026): las reglas de negocio de los Services (PedidoService,
        // PagoService, CajaService, ReservaService...) se señalan lanzando RuntimeException
        // o InvalidArgumentException directo (este proyecto no tiene una clase de excepción
        // propia) — ningún Controller las capturaba, así que llegaban sin manejar hasta el
        // handler por defecto de Laravel: un 500 con la traza completa expuesta (APP_DEBUG=true)
        // o un "Server Error" genérico sin ninguna pista (APP_DEBUG=false), en vez de un 4xx
        // con el mensaje real (ej. "No hay un turno de caja abierto para registrar el cobro").
        // Se excluyen los RuntimeException que YA tienen su propio render correcto y no son
        // una regla de negocio: ModelNotFoundException (404 automático del route-model-binding),
        // toda la familia HttpException (abort()/abort_if(), 404 de ruta no encontrada, 405,
        // etc.) y HttpResponseException (ya trae su propia Response armada).
        $esReglaDeNegocio = function (\Throwable $e) {
            return ! $e instanceof ModelNotFoundException
                && ! $e instanceof HttpExceptionInterface
                && ! $e instanceof HttpResponseException;
        };

        $exceptions->render(function (\RuntimeException $e, Request $request) use ($esReglaDeNegocio) {
            if (! $request->is('api/*') || ! $esReglaDeNegocio($e)) {
                return null;
            }

            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (\InvalidArgumentException $e, Request $request) use ($esReglaDeNegocio) {
            if (! $request->is('api/*') || ! $esReglaDeNegocio($e)) {
                return null;
            }

            return response()->json(['message' => $e->getMessage()], 422);
        });
    })->create();
