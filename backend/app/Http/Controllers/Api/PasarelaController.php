<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TransaccionPasarela;
use App\Services\PasarelaPagoService;
use App\Support\Pasarela\FirmaInvalidaException;
use App\Support\Pasarela\PasarelaPago;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Fase 6, tarea 1 (RF-M06-006, decisión D4: Wompi): eventos de la pasarela, página de retorno y
 * lo que el Administrador revisa. Ver PasarelaPagoService.
 */
class PasarelaController extends Controller
{
    public function __construct(private readonly PasarelaPagoService $servicio)
    {
    }

    // POST /api/pasarela/wompi/eventos — público: lo llama Wompi. Solo se aplica un evento con la
    // firma correcta (secreto de eventos). Responde 200 a todo evento válido, aunque no sea de este
    // sistema, para que Wompi no lo reintente sin fin; 401 si la firma no cuadra.
    public function evento(Request $request)
    {
        try {
            $resultado = $this->servicio->procesarEvento($request->json()->all(), [
                'x-event-checksum' => $request->header('X-Event-Checksum'),
            ]);
        } catch (FirmaInvalidaException $e) {
            Log::warning('Evento de la pasarela rechazado por firma.', ['ip' => $request->ip()]);

            return response()->json(['message' => $e->getMessage()], 401);
        }

        return response()->json(['resultado' => $resultado]);
    }

    // GET /api/pasarela/transacciones/{referencia}?id= — público: la página a la que vuelve el
    // Cliente. La referencia es secreta (20 caracteres al azar) y la ruta tiene límite de intentos.
    public function retorno(Request $request, string $referencia)
    {
        $request->validate(['id' => ['nullable', 'string', 'max:80']]);

        return response()->json($this->servicio->estadoParaRetorno($referencia, $request->query('id')));
    }

    // GET /api/pasarela/estado (Administrador): si la pasarela está activa y a dónde configurar los eventos.
    public function estado(PasarelaPago $pasarela)
    {
        return response()->json([
            'proveedor' => config('fragua_real.pasarela.proveedor'),
            'activa' => $this->servicio->activa(),
            'configurada' => $pasarela->configurada(),
            'ambiente' => config('services.wompi.ambiente'),
            'url_eventos' => url('/api/pasarela/wompi/eventos'),
            'por_revisar' => TransaccionPasarela::where('requiere_revision', true)->whereNull('revisado_at')->count(),
        ]);
    }

    // GET /api/pasarela/transacciones (Administrador): ?revision=1 solo las que hay que revisar.
    public function index(Request $request)
    {
        $consulta = TransaccionPasarela::with(['pagable', 'revisadoPorUsuario:id,nombre'])->latest('id');
        if ($request->boolean('revision')) {
            $consulta->where('requiere_revision', true)->whereNull('revisado_at');
        }

        return response()->json($consulta->paginate(20)->through(fn (TransaccionPasarela $tx) => [
            ...PasarelaPagoService::resumen($tx),
            'monto' => (float) $tx->monto,
            'tipo' => $tx->pagable instanceof \App\Models\Reserva ? 'reserva' : 'pedido',
            'pedido_id' => $tx->pagable instanceof \App\Models\Pago ? $tx->pagable->pedido_id : null,
            'reserva_id' => $tx->pagable instanceof \App\Models\Reserva ? $tx->pagable->id : null,
            'revisado_por' => $tx->revisadoPorUsuario?->nombre,
        ]));
    }

    // POST /api/pasarela/transacciones/{transaccion}/revisada (Administrador): con lo que hizo.
    public function revisada(Request $request, TransaccionPasarela $transaccion)
    {
        $datos = $request->validate(['nota' => ['required', 'string', 'min:5', 'max:255']]);

        return response()->json(PasarelaPagoService::resumen($this->servicio->marcarRevisada($transaccion, $request->user(), $datos['nota'])));
    }

    // POST /api/pasarela/transacciones/{transaccion}/reembolsar (Administrador): volver a pedir a la
    // pasarela un reembolso que falló. El reembolso inicial lo pide la anulación del pago o la
    // cancelación de la reserva; aquí solo se reintenta uno ya decidido.
    public function reembolsar(Request $request, TransaccionPasarela $transaccion)
    {
        $pagable = $transaccion->pagable;
        $decidido = ($pagable instanceof \App\Models\Pago && $pagable->anulado && $pagable->reverso_tipo === 'reembolso')
            || ($pagable instanceof \App\Models\Reserva && $pagable->deposito_reembolsado);
        abort_unless($decidido, 422, 'Primero reembolsa el pago (Caja) o cancela la reserva; aquí solo se reintenta un reembolso que falló.');
        abort_unless($transaccion->reembolso_estado === 'fallido', 422, 'Este reembolso no falló: no hay nada que reintentar.');

        $transaccion->forceFill(['reembolso_estado' => null])->save();

        return response()->json(PasarelaPagoService::resumen($this->servicio->reembolsar($transaccion, $request->user())));
    }
}
