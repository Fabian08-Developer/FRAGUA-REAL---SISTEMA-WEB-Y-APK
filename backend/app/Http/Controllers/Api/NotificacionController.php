<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notificacion;
use App\Services\NotificacionService;
use Illuminate\Http\Request;

/**
 * M11 (21/10/2026): la campana del personal. Cada cuenta ve y marca solo sus notificaciones; no se
 * crean ni se borran desde aquí (las crea NotificacionService y se borran solas a los 30 días).
 */
class NotificacionController extends Controller
{
    public const LIMITE = 30;

    // GET /notificaciones — las más recientes y cuántas faltan por leer.
    public function index(Request $request)
    {
        $usuario = $request->user();

        return response()->json([
            'data' => Notificacion::where('user_id', $usuario->id)->latest('id')->limit(self::LIMITE)->get(),
            'no_leidas' => $this->noLeidas($usuario->id),
        ]);
    }

    // GET /notificaciones/contador — lo que la campana pregunta cada 20 segundos.
    public function contador(Request $request, NotificacionService $notificaciones)
    {
        $usuario = $request->user();
        // Si el programador de tareas se detuvo, nada programado lo avisaría: se revisa aquí.
        $notificaciones->revisarProgramador($usuario);

        return response()->json([
            'no_leidas' => $this->noLeidas($usuario->id),
            'ultima_id' => Notificacion::where('user_id', $usuario->id)->max('id'),
        ]);
    }

    // POST /notificaciones/{notificacion}/leida
    public function marcarLeida(Request $request, Notificacion $notificacion)
    {
        abort_if($notificacion->user_id !== $request->user()->id, 404);
        if (! $notificacion->leida_at) {
            $notificacion->forceFill(['leida_at' => now()])->save();
        }

        return response()->json(['notificacion' => $notificacion, 'no_leidas' => $this->noLeidas($request->user()->id)]);
    }

    // POST /notificaciones/leidas — marca todas como leídas.
    public function marcarTodas(Request $request)
    {
        $marcadas = Notificacion::where('user_id', $request->user()->id)->whereNull('leida_at')->update(['leida_at' => now()]);

        return response()->json(['marcadas' => $marcadas, 'no_leidas' => 0]);
    }

    private function noLeidas(int $userId): int
    {
        return Notificacion::where('user_id', $userId)->whereNull('leida_at')->count();
    }
}
