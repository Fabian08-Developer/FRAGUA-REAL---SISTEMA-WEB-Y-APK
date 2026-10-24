<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\NegocioService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Mejora (19/10/2026): dirección, teléfono y logo del negocio, editables por el Administrador en
 * Configuración (ver NegocioService). Los lee cualquiera en GET /configuracion (campo `negocio`).
 */
class NegocioController extends Controller
{
    public function __construct(private readonly NegocioService $negocio)
    {
    }

    // PUT /negocio (Administrador)
    public function actualizar(Request $request)
    {
        // 22/10/2026: teléfono solo con números (10 dígitos) y NIT sin puntos ni espacios.
        \App\Support\Validacion\Formatos::normalizar($request, ['telefono' => 'telefono', 'nit' => 'documento', 'nombre' => 'nombre']);
        $datos = $request->validate([
            'direccion' => ['required', 'string', 'max:160'],
            // Con él se arma el enlace de WhatsApp: 10 dígitos (celular o fijo de Colombia).
            'telefono' => ['required', 'string', 'regex:'.\App\Support\Validacion\Formatos::CELULAR],
            // M09 (19/10/2026): nombre y NIT, solo del SuperAdmin.
            'nombre' => ['sometimes', 'required', 'string', 'max:120'],
            'nit' => ['sometimes', 'nullable', 'string', 'regex:'.\App\Support\Validacion\Formatos::DOCUMENTO],
            // M09 (20/10/2026): el punto del local en el mapa (los dos o ninguno), solo del SuperAdmin.
            'latitud' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitud'],
            'longitud' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitud'],
        ], [
            'latitud.between' => 'La latitud no es válida.',
            'longitud.between' => 'La longitud no es válida.',
            'nombre.required' => 'Escribe el nombre del negocio.',
            'nit.regex' => \App\Support\Validacion\Formatos::MENSAJE_DOCUMENTO,
            'direccion.required' => 'Escribe la dirección del negocio.',
            'telefono.required' => 'Escribe el teléfono del negocio.',
            'telefono.regex' => \App\Support\Validacion\Formatos::MENSAJE_CELULAR,
        ]);

        $soloSuperAdmin = array_intersect_key($datos, ['nombre' => true, 'nit' => true, 'latitud' => true, 'longitud' => true]);
        abort_if($soloSuperAdmin && ! $request->user()->esSuperAdmin(), 403, 'Solo el SuperAdmin puede cambiar el nombre, el NIT y la ubicación del negocio.');

        return response()->json($this->negocio->actualizar($datos, $request->user()));
    }

    // POST /negocio/ubicacion-desde-enlace (SuperAdmin): las coordenadas de un enlace de Google Maps.
    // No guarda nada: la pantalla mueve el marcador y el SuperAdmin decide si guarda.
    public function ubicacionDesdeEnlace(Request $request)
    {
        abort_if(! $request->user()->esSuperAdmin(), 403, 'Solo el SuperAdmin puede cambiar la ubicación del negocio.');
        $datos = $request->validate(['enlace' => ['required', 'string', 'max:2000']], ['enlace.required' => 'Pega el enlace de Google Maps.']);

        $punto = $this->negocio->coordenadasDeEnlace($datos['enlace']);
        abort_if($punto === null, 422, 'No encontramos la ubicación en ese enlace. En Google Maps, toca el lugar, luego «Compartir» y copia el enlace; o escribe las coordenadas, por ejemplo 1.6144, -75.6062.');

        return response()->json($punto);
    }

    // POST /negocio/logo (Administrador)
    public function subirLogo(Request $request)
    {
        $request->validate([
            'logo' => [
                'required', 'file', 'mimes:png,jpg,jpeg,webp', 'mimetypes:image/png,image/jpeg,image/webp',
                'max:'.config('fragua_real.foto_producto_max_kb', 5120),
                'dimensions:min_width=64,min_height=64',
            ],
        ], [
            'logo.mimes' => 'El logo debe ser PNG, JPG o WebP.',
            'logo.mimetypes' => 'El logo debe ser PNG, JPG o WebP.',
            'logo.max' => 'El logo pesa demasiado (máximo 5 MB).',
            'logo.dimensions' => 'El logo debe medir al menos 64 × 64 px.',
        ]);

        try {
            return response()->json($this->negocio->guardarLogo($request->file('logo'), $request->user()));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    // DELETE /negocio/logo (Administrador)
    public function quitarLogo(Request $request)
    {
        return response()->json($this->negocio->quitarLogo($request->user()));
    }

    // GET /negocio/logo (pública): el archivo del logo.
    public function logo()
    {
        $ruta = $this->negocio->rutaLogo();
        abort_if(! $ruta, 404);

        return Storage::disk(NegocioService::DISCO)->response($ruta, 'logo.png', [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ], 'inline');
    }

    // GET /negocio/pagos-directos (Administrador): las cuentas del pago directo, también las que están
    // a medio llenar (las ve el Administrador; las cambia el SuperAdmin).
    public function pagosDirectos(\App\Services\PagoDirectoService $pagosDirectos)
    {
        return response()->json(['datos' => $pagosDirectos->datos(), 'publico' => $pagosDirectos->publico()]);
    }

    // PUT /negocio/pagos-directos (solo SuperAdmin, RN-M06-17): Nequi, Bancolombia y la llave Bre-B.
    public function actualizarPagosDirectos(Request $request, \App\Services\PagoDirectoService $pagosDirectos)
    {
        abort_if(! $request->user()->esSuperAdmin(), 403, 'Solo el SuperAdmin puede cambiar las cuentas donde se recibe el dinero.');
        // 22/10/2026: números sin espacios ni guiones, titulares solo con letras.
        \App\Support\Validacion\Formatos::normalizar($request, [
            'nequi.numero' => 'telefono', 'bancolombia.numero' => 'digitos', 'bancolombia.documento' => 'documento',
            'nequi.titular' => 'nombre', 'bancolombia.titular' => 'nombre', 'breb.titular' => 'nombre',
        ]);
        $titular = ['nullable', 'string', 'max:80', 'regex:'.\App\Support\Validacion\Formatos::PERSONA];
        $datos = $request->validate([
            'nequi.numero' => ['nullable', 'string', 'regex:/^3\d{9}$/'],
            'nequi.titular' => $titular,
            'bancolombia.tipo' => ['nullable', 'in:ahorros,corriente'],
            'bancolombia.numero' => ['nullable', 'string', 'regex:'.\App\Support\Validacion\Formatos::CUENTA_BANCOLOMBIA],
            'bancolombia.titular' => $titular,
            'bancolombia.documento' => ['nullable', 'string', 'regex:'.\App\Support\Validacion\Formatos::DOCUMENTO],
            'breb.llave' => ['nullable', 'string', 'max:80', 'regex:'.\App\Support\Validacion\Formatos::LLAVE_BREB],
            'breb.titular' => $titular,
        ], [
            'nequi.numero.regex' => 'Escribe el número de celular de Nequi: 10 dígitos que empiezan por 3.',
            'bancolombia.numero.regex' => \App\Support\Validacion\Formatos::MENSAJE_CUENTA,
            'bancolombia.documento.regex' => \App\Support\Validacion\Formatos::MENSAJE_DOCUMENTO,
            'breb.llave.regex' => \App\Support\Validacion\Formatos::MENSAJE_LLAVE,
            'nequi.titular.regex' => \App\Support\Validacion\Formatos::MENSAJE_PERSONA,
            'bancolombia.titular.regex' => \App\Support\Validacion\Formatos::MENSAJE_PERSONA,
            'breb.titular.regex' => \App\Support\Validacion\Formatos::MENSAJE_PERSONA,
        ]);
        $pagosDirectos->actualizar($datos, $request->user());

        return $this->pagosDirectos($pagosDirectos);
    }
}
