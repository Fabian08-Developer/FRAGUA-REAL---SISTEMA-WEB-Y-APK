<?php

namespace App\Http\Requests\Api\Reserva;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

class StoreReservaRequest extends FormRequest
{
    // Igual que CajaService::ZONA_HORARIA_NEGOCIO: la app corre en 'UTC' (config('app.timezone')),
    // pero el restaurante opera en hora de Colombia — se define localmente aquí, sin tocar el
    // timezone global (eso afectaría casts/logs/etc. de todo el sistema).
    //
    // CORREGIDO (01/10/2026): ReservaView.vue envía fecha_hora como "Y-m-d\TH:i" sin zona
    // horaria (`${form.fecha}T${form.hora}`), queriendo decir esa hora EN BOGOTÁ. Antes se
    // validaba y se guardaba tal cual, como si ya fuera UTC — cada reserva quedaba registrada
    // ~5 horas antes de la hora que el Cliente realmente pidió, lo que corrompía RN-M05-09/17
    // ("antes de su hora", reembolso de depósito) y marcarNoShow(). Se convierte aquí, antes
    // de que corra la regla 'after:now' y antes de que el Controller/Service la guarde, para
    // que todo lo que sigue ya trabaje con el instante UTC correcto.
    private const ZONA_HORARIA_NEGOCIO = 'America/Bogota';

    protected function prepareForValidation(): void
    {
        \App\Support\Validacion\Formatos::normalizar($this, ['cliente_telefono' => 'telefono', 'cliente_nombre' => 'nombre']);
        if (! $this->filled('fecha_hora') || ! is_string($this->fecha_hora)) {
            return;
        }

        try {
            $utc = Carbon::parse($this->fecha_hora, self::ZONA_HORARIA_NEGOCIO)->utc();
            $this->merge(['fecha_hora' => $utc->toIso8601String()]);
        } catch (\Throwable) {
            // Se deja el valor original — la regla 'date' de abajo lo rechaza igual.
        }
    }

    public function authorize(): bool
    {
        return true; // Cliente web o Mesero (creado_por lo distingue); ver ReservaController.
    }

    public function messages(): array
    {
        return [
            'cliente_telefono.regex' => \App\Support\Validacion\Formatos::MENSAJE_CELULAR,
            'cliente_nombre.regex' => \App\Support\Validacion\Formatos::MENSAJE_PERSONA,
            'fecha_hora.after' => 'La reserva tiene que ser para una fecha y hora futuras.',
            'mesa_id.exists' => 'La mesa no existe o está desactivada.',
        ];
    }

    public function rules(): array
    {
        return [
            // Fase 1, tarea 4: solo mesas activas reciben reservas.
            'mesa_id' => ['required', \Illuminate\Validation\Rule::exists('mesas', 'id')->where('activa', true)],
            'cliente_nombre' => ['required', 'string', 'max:80', 'regex:'.\App\Support\Validacion\Formatos::PERSONA],
            'cliente_telefono' => ['required', 'string', 'regex:'.\App\Http\Requests\Api\Pedido\StorePedidoRequest::FORMATO_TELEFONO], // RN-M05-12
            'cliente_correo' => ['required', 'email', 'max:120'],   // RN-M05-12
            'fecha_hora' => ['required', 'date', 'after:now'],
            // Corrección (19/10/2026): una nota del cliente para el restaurante (cumpleaños, silla
            // para bebé, cerca de la ventana…). Opcional.
            'descripcion' => ['nullable', 'string', 'max:500'],
        ];
    }

    // Corrección (19/10/2026): horario de reservas (4:00 p. m. a 11:00 p. m.) y al menos 3 horas
    // de anticipación, para la web y para las que registra el personal (PoliticaReservas).
    public function after(): array
    {
        return [
            function (\Illuminate\Validation\Validator $validator) {
                if ($validator->errors()->has('fecha_hora')) {
                    return;
                }
                $motivo = \App\Support\Reservas\PoliticaReservas::motivoHoraNoPermitida(Carbon::parse($this->input('fecha_hora')));
                if ($motivo) {
                    $validator->errors()->add('fecha_hora', $motivo);
                }
            },
        ];
    }
}
