<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Reserva\ConfirmarDepositoReservaRequest;
use App\Http\Requests\Api\Reserva\RegistrarDepositoManualRequest;
use App\Http\Requests\Api\Reserva\StoreReservaRequest;
use App\Models\Configuracion;
use App\Models\Reserva;
use App\Services\ReservaService;
use Illuminate\Http\Request;

class ReservaController extends Controller
{
    public function __construct(private readonly ReservaService $reservaService)
    {
    }

    // M05 (Admin): listado con filtro opcional por estado y/o por mesa (?mesa_id=) — este
    // último es el historial de reservas de una mesa concreta, enlazado desde "Ver
    // reservas" en MesasView.vue. Ver EstadoReserva en frontend/src/types/models.ts para
    // los valores válidos de estado.
    public function index(Request $request)
    {
        $query = Reserva::with('mesa')->latest('fecha_hora');

        if ($request->filled('estado')) {
            $query->where('estado', $request->string('estado'));
        }

        if ($request->filled('mesa_id')) {
            $query->where('mesa_id', $request->integer('mesa_id'));
        }

        // Fase 1, tarea 4: ?proximas=1 → las que el salón tiene que atender, de hoy en adelante
        // (hora de Bogotá), pendientes o confirmadas, de la más cercana a la más lejana.
        if ($request->boolean('proximas')) {
            $query->reorder()
                ->whereIn('estado', ['pendiente_pago', 'confirmada'])
                ->where('fecha_hora', '>=', now('America/Bogota')->startOfDay()->utc())
                ->orderBy('fecha_hora');
        }

        return response()->json($query->with('registradaPorUsuario:id,nombre')->paginate(20));
    }

    // Cliente web, Mesero o Administrador (RN-M05-06): creado_por distingue el origen.
    // CORREGIDO (Fase 1, tarea 4, 11/10/2026): POST /reservas es una ruta pública, así que
    // $request->user() siempre era null y toda reserva quedaba como "cliente_web", también las
    // que registraba el personal por teléfono. Ahora se pide la sesión a Sanctum (igual que en
    // StorePedidoRequest) y se guarda quién la registró.
    public function store(StoreReservaRequest $request)
    {
        $data = $request->validated();
        $usuario = $request->user('sanctum');
        $esPersonal = $usuario && ($usuario->esMesero() || $usuario->esAdministrador());
        $data['creado_por'] = $esPersonal ? $usuario->role : 'cliente_web';

        // Valor vigente fijado por el servidor, no por el Cliente (mismo criterio que
        // valor_domicilio en PedidoController) — tabla `configuraciones`.
        $depositoValor = Configuracion::obtenerNumerico(
            'deposito_reserva',
            (float) config('fragua_real.deposito_reserva_default')
        );
        $reserva = $this->reservaService->crear($data, $depositoValor, $esPersonal ? $usuario : null);

        return response()->json($reserva->load('mesa'), 201);
    }

    // Fase 1, tarea 4 (RN-M05-07): mesas activas que no tienen otra reserva a esa hora. Pública:
    // la usan la reserva web del Cliente y la del personal. Solo devuelve mesas, sin datos de nadie.
    public function mesasDisponibles(Request $request)
    {
        $request->validate(['fecha_hora' => ['required', 'date']]);
        $fechaHora = \Illuminate\Support\Carbon::parse($request->string('fecha_hora'), 'America/Bogota')->utc();

        return response()->json($this->reservaService->mesasDisponibles($fechaHora));
    }

    // RN-M05-16: el Cliente inicia el pago del depósito por Nequi/Bancolombia (público).
    public function registrarDepositoEnLinea(ConfirmarDepositoReservaRequest $request, Reserva $reserva)
    {
        return response()->json($this->reservaService->registrarDepositoEnLinea($reserva, $request->pasarela)->load('mesa'));
    }

    // Hoy: acción manual de Administrador/Mesero; mañana la reemplaza el webhook real de
    // la pasarela (ver README-BACKEND.md, "Pasarela de pago real").
    // ->load('mesa') en todas las acciones de abajo: la vista de Administrador reemplaza
    // la reserva en su lista con esta misma respuesta y necesita mesa.nombre, igual que
    // index() (sin esto, tras cualquier acción la mesa se vería como "Mesa #id").
    public function confirmarDepositoEnLinea(Request $request, Reserva $reserva)
    {
        abort_if(! ($request->user()->esAdministrador() || $request->user()->esMesero()), 403);

        return response()->json($this->reservaService->confirmarDepositoEnLinea($reserva, $request->user())->load('mesa'));
    }

    public function rechazarDepositoEnLinea(Request $request, Reserva $reserva)
    {
        abort_if(! ($request->user()->esAdministrador() || $request->user()->esMesero()), 403);

        return response()->json($this->reservaService->rechazarDepositoEnLinea($reserva, $request->user())->load('mesa'));
    }

    // RN-M05-16: depósito manual registrado por el Mesero o el Administrador.
    public function registrarDepositoManual(RegistrarDepositoManualRequest $request, Reserva $reserva)
    {
        return response()->json(
            $this->reservaService->registrarDepositoManual($reserva, $request->metodo, $request->user())->load('mesa')
        );
    }

    public function marcarNoShow(Request $request, Reserva $reserva)
    {
        abort_if(! ($request->user()->esAdministrador() || $request->user()->esMesero()), 403);

        return response()->json($this->reservaService->marcarNoShow($reserva)->load('mesa'));
    }

    public function completar(Request $request, Reserva $reserva)
    {
        abort_if(! ($request->user()->esAdministrador() || $request->user()->esMesero()), 403);

        return response()->json($this->reservaService->completar($reserva, $request->user())->load('mesa'));
    }

    public function cancelar(Request $request, Reserva $reserva)
    {
        // CORREGIDO: antes esta acción no tenía ninguna autorización fina — cualquier
        // Cliente autenticado podía cancelar la reserva de otro con solo conocer su id.
        // RN-M05-09 ("por quien la creó, o por el Mesero/Administrador") vive en
        // ReservaPolicy::cancelar() (ver ahí por qué se identifica por correo).
        $this->authorize('cancelar', $reserva);

        return response()->json($this->reservaService->cancelar($reserva)->load('mesa'));
    }

    // "Mis reservas" del Cliente (HU-M05, pantalla "Mis pedidos y reservas"): Reserva no
    // tiene cliente_id (RN-M05-12), así que se identifican por el correo de contacto —
    // mismo criterio que ReservaPolicy::cancelar().
    public function misReservas(Request $request)
    {
        $reservas = Reserva::with('mesa')
            ->where('cliente_correo', $request->user()->email)
            ->latest('fecha_hora')
            ->paginate(20);

        return response()->json($reservas);
    }

    // RF-M05-035, HU-M05-015: depósitos retenidos por no-show (RN-M05-18) — un depósito
    // pagado que nunca se reembolsó (cancelar()) ni se aplicó como abono (completar()),
    // porque el cliente no llegó. Exclusivo Administrador (ver routes/api.php).
    public function depositosRetenidos(Request $request)
    {
        $reservas = Reserva::with('mesa')
            ->where('estado', 'no_show')
            ->where('deposito_pagado', true)
            ->where('deposito_reembolsado', false)
            ->where('deposito_aplicado_abono', false)
            ->latest('fecha_hora')
            ->get();

        return response()->json([
            'total_retenido' => $reservas->sum('deposito_valor'),
            'reservas' => $reservas,
        ]);
    }
}
