<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Usuario\StoreUsuarioRequest;
use App\Http\Requests\Api\Usuario\UpdateUsuarioRequest;
use App\Models\User;
use App\Services\AuditoriaService;
use App\Services\SesionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        // Fase 3, tarea 4: cuántas sesiones vigentes tiene cada cuenta.
        $usuarios = User::query()
            ->withCount(['tokens as sesiones_activas' => fn ($q) => app(SesionService::class)->vigentes($q)])
            ->when($request->rol, fn ($q) => $q->where('role', $request->rol))
            ->when($request->busqueda, fn ($q) => $q->where(
                fn ($q2) => $q2->where('nombre', 'like', "%{$request->busqueda}%")
                    ->orWhere('email', 'like', "%{$request->busqueda}%")
                    ->orWhere('username', 'like', '%'.mb_strtolower((string) $request->busqueda).'%')
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
            'username' => $request->validated('username'),
            'telefono' => $request->telefono,
            'password' => Hash::make($request->password),
            'activo' => true,
        ]);
        // RF-M01-017 (Fase 3, tarea 1): el alta de una cuenta del personal queda auditada.
        app(AuditoriaService::class)->registrar('crear_usuario', 'User', $usuario->id, [
            'rol' => $usuario->role,
            'nombre' => $usuario->nombre,
            'correo' => $usuario->email,
            'usuario' => $usuario->username,
        ], $request->user());

        return response()->json($usuario, 201);
    }

    // RF-M01-003/005, HU-M01-003/005 (Fase 3, tarea 2): corregir nombre, correo y teléfono de una
    // cuenta y cambiar su rol. La contraseña no se toca aquí (CA-M01-003.1): cada quien la cambia en
    // su perfil o con "¿Olvidaste tu contraseña?".
    //  - Solo roles del catálogo (RF-M01-022, validado en UpdateUsuarioRequest).
    //  - El Administrador no cambia su propio rol: podría quedarse sin acceso a esta pantalla.
    //  - Un cambio de rol cierra todas las sesiones de esa cuenta (RF-M01-023): sus permisos
    //    cambian y debe volver a entrar; así ninguna pestaña abierta sigue con el rol anterior.
    //  - Queda en la auditoría: los datos cambiados con antes → después (`editar_usuario`) y el
    //    cambio de rol aparte (`cambiar_rol`), para poder filtrarlo.
    public function update(UpdateUsuarioRequest $request, User $usuario)
    {
        $datos = $request->validated();
        $cambiaRol = $datos['role'] !== $usuario->role;

        abort_if($cambiaRol && $usuario->is($request->user()), 422, 'No puedes cambiar tu propio rol. Pídeselo a otro Administrador.');

        $campos = array_values(array_intersect(['nombre', 'email', 'username', 'telefono'], array_keys($datos)));
        $antes = $usuario->only([...$campos, 'role']);

        DB::transaction(function () use ($usuario, $datos, $cambiaRol, $campos, $antes, $request) {
            $usuario->update($datos);

            $cambios = collect($campos)
                ->filter(fn (string $campo) => (string) ($antes[$campo] ?? '') !== (string) ($datos[$campo] ?? ''))
                ->mapWithKeys(fn (string $campo) => [$campo => ['antes' => $antes[$campo], 'despues' => $datos[$campo] ?? null]])
                ->all();

            if ($cambios) {
                app(AuditoriaService::class)->registrar('editar_usuario', 'User', $usuario->id, ['cambios' => $cambios], $request->user());
            }

            if ($cambiaRol) {
                $sesiones = $usuario->tokens()->count();
                $usuario->tokens()->delete();
                app(AuditoriaService::class)->registrar('cambiar_rol', 'User', $usuario->id, [
                    'cambios' => ['rol' => ['antes' => $antes['role'], 'despues' => $datos['role']]],
                    'sesiones_cerradas' => $sesiones,
                ], $request->user());
            }
        });

        return response()->json([
            'usuario' => $usuario->fresh(),
            'sesiones_cerradas' => $cambiaRol,
        ]);
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

    // RF-M01-010, RF-M01-024, HU-M01-010 (Fase 3, tarea 4): las sesiones abiertas de una cuenta,
    // con dispositivo, IP, último uso y vencimiento, para revisar o cerrar una en particular.
    public function sesiones(User $usuario, SesionService $sesiones)
    {
        return response()->json([
            'sesiones' => $sesiones->listar($usuario),
            'politica' => $sesiones->politica(),
        ]);
    }

    public function revocarSesion(Request $request, User $usuario, SesionService $sesiones, int $sesion)
    {
        $this->impedirAutogestion($request, $usuario);
        $cerrada = $sesiones->cerrar($usuario, $sesion);
        abort_if(! $cerrada, 404, 'Esa sesión ya no está abierta.');

        app(AuditoriaService::class)->registrar('revocar_sesion', 'User', $usuario->id, [
            'sesion_id' => $sesion, 'dispositivo' => $cerrada['dispositivo'], 'ip' => $cerrada['ip'],
        ], $request->user());

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    // Un Administrador no puede desactivarse ni revocarse sus propias sesiones desde esta
    // pantalla — se quedaría sin forma de deshacerlo (no hay otro Administrador de por
    // medio todavía, y esto no es un borrado que un superadmin pueda revertir).
    private function impedirAutogestion(Request $request, User $usuario): void
    {
        abort_if($usuario->is($request->user()), 422, 'No puedes hacer esto sobre tu propia cuenta.');
    }
}
