<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Usuario\StoreUsuarioRequest;
use App\Models\User;
use App\Services\AuditoriaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * M01 — Gestión de Usuarios del Administrador: listar, crear cuentas de Mesero/Administrador,
 * activar/desactivar y revocar sesiones. El autorregistro de Cliente vive aparte en
 * AuthController::register() (RegisterClienteRequest) — este Controller es exclusivo para las
 * cuentas de staff que el Administrador crea y administra (role:administrador, routes/api.php).
 * revocarSesiones() vivía en AuthController como solución provisional (su propio comentario lo
 * documentaba: "no existe todavía un UsuarioController dedicado") — se traslada aquí ahora que
 * sí existe.
 */
class UsuarioController extends Controller
{
    // RF-M01: listado con búsqueda simple (nombre/correo) y filtro por rol — el Administrador
    // ve staff y clientes desde la misma pantalla.
    public function index(Request $request)
    {
        $usuarios = User::query()
            ->when($request->rol, fn ($q) => $q->where('role', $request->rol))
            ->when($request->busqueda, fn ($q) => $q->where(
                fn ($q2) => $q2->where('nombre', 'like', "%{$request->busqueda}%")
                    ->orWhere('email', 'like', "%{$request->busqueda}%")
            ))
            ->orderBy('nombre')
            ->paginate(20);

        return response()->json($usuarios);
    }

    // Crea una cuenta de Mesero o Administrador. El Administrador define la contraseña
    // inicial y se la comunica él mismo — M01 no pide enviarla por correo.
    public function store(StoreUsuarioRequest $request)
    {
        $usuario = User::create([
            'role' => $request->role,
            'nombre' => $request->nombre,
            'email' => $request->email,
            'telefono' => $request->telefono,
            'password' => Hash::make($request->password),
            'activo' => true,
        ]);

        return response()->json($usuario, 201);
    }

    // Activar/desactivar es un cambio de estado (columna `activo`), no un borrado — igual
    // que Mesa::activa o la disponibilidad de un Producto. AuthController::login() ya
    // rechaza a un usuario inactivo, pero un token ya emitido seguiría funcionando hasta
    // que el Administrador también revoque sus sesiones (revocarSesiones() abajo) — son dos
    // acciones separadas a propósito (activar/desactivar es reversible y frecuente; revocar
    // sesiones es más drástico), el frontend las ofrece juntas para que no se olvide una.
    public function activar(Request $request, User $usuario)
    {
        $this->impedirAutogestion($request, $usuario);
        $usuario->update(['activo' => true]);
        app(AuditoriaService::class)->registrar('activar_usuario', 'User', $usuario->id, [], $request->user());

        return response()->json($usuario->fresh());
    }

    public function desactivar(Request $request, User $usuario)
    {
        $this->impedirAutogestion($request, $usuario);
        $usuario->update(['activo' => false]);
        // RF-M01-023, RN-USR-06 (Fase 0, tarea 6): desactivar cierra todas sus sesiones. Antes
        // había que pedirlo aparte con "revocar sesiones".
        $usuario->tokens()->delete();
        // RF-M01-017: activar, desactivar y revocar sesiones quedan auditados.
        app(AuditoriaService::class)->registrar('desactivar_usuario', 'User', $usuario->id, ['sesiones_revocadas' => true], $request->user());

        return response()->json($usuario->fresh());
    }

    // RF-M01-023: el Administrador revoca las sesiones de otro usuario (ej. al desactivar
    // una cuenta de Mesero, o si sospecha que un token quedó comprometido).
    public function revocarSesiones(Request $request, User $usuario)
    {
        $this->impedirAutogestion($request, $usuario);
        $usuario->tokens()->delete();
        app(AuditoriaService::class)->registrar('revocar_sesiones', 'User', $usuario->id, [], $request->user());

        return response()->json(['message' => 'Sesiones revocadas.']);
    }

    // Un Administrador no puede desactivarse ni revocarse sus propias sesiones desde esta
    // pantalla — se quedaría sin forma de deshacerlo (no hay otro Administrador de por
    // medio todavía, y esto no es un borrado que un superadmin pueda revertir).
    private function impedirAutogestion(Request $request, User $usuario): void
    {
        abort_if($usuario->is($request->user()), 422, 'No puedes hacer esto sobre tu propia cuenta.');
    }
}
