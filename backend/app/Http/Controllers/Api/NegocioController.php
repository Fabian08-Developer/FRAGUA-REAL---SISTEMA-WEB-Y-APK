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
        $datos = $request->validate([
            'direccion' => ['required', 'string', 'max:160'],
            // Se muestra tal cual y con él se arma el enlace de WhatsApp: de 7 a 15 dígitos.
            'telefono' => ['required', 'string', 'max:30', 'regex:/^\+?[\d\s\-()]{7,25}$/'],
            // M09 (19/10/2026): nombre y NIT, solo del SuperAdmin.
            'nombre' => ['sometimes', 'required', 'string', 'max:120'],
            'nit' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^[\d.\-\s]*$/'],
            // M09 (20/10/2026): el punto del local en el mapa (los dos o ninguno), solo del SuperAdmin.
            'latitud' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitud'],
            'longitud' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitud'],
        ], [
            'latitud.between' => 'La latitud no es válida.',
            'longitud.between' => 'La longitud no es válida.',
            'nombre.required' => 'Escribe el nombre del negocio.',
            'nit.regex' => 'El NIT solo lleva números, puntos y guion (por ejemplo, 1075320008-5).',
            'direccion.required' => 'Escribe la dirección del negocio.',
            'telefono.required' => 'Escribe el teléfono del negocio.',
            'telefono.regex' => 'Escribe un teléfono válido (solo números, espacios y guiones).',
        ]);
        $digitos = strlen(preg_replace('/\D+/', '', $datos['telefono']));
        abort_if($digitos < 7 || $digitos > 15, 422, 'Escribe un teléfono válido, de 7 a 15 dígitos.');

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
}
