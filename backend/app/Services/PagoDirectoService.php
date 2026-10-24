<?php

namespace App\Services;

use App\Models\Configuracion;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Pago directo (22/10/2026, RF-M06-030 a 035, RN-M06-16 a 19): mientras no haya cuenta de Wompi, el
 * Cliente transfiere a las cuentas del negocio y sube la captura del comprobante; el personal la revisa.
 *
 *  - Las cuentas (Nequi, Bancolombia y la llave Bre-B) las cambia solo el SuperAdmin en Configuración
 *    (tabla `configuraciones`) y quedan en la auditoría. Al Cliente solo se le ofrecen los medios con datos.
 *  - Con Wompi activo (config/fragua_real.php, «pasarela») este modo no se usa: el pago vuelve a ser
 *    automático y sin captura.
 *  - Las capturas se guardan en el disco privado (`storage/app/private/comprobantes-pago`), entran en el
 *    respaldo diario y solo las ve el personal, por la API.
 */
class PagoDirectoService
{
    public const DISCO = 'local';
    public const CARPETA = 'comprobantes-pago';

    /** medio => [nombre, [campo => etiqueta]] (el orden es el que ve el Cliente). */
    public const MEDIOS = [
        'nequi' => ['Nequi', ['numero' => 'Número de Nequi', 'titular' => 'A nombre de']],
        'bancolombia' => ['Bancolombia', ['tipo' => 'Tipo de cuenta', 'numero' => 'Número de cuenta', 'titular' => 'A nombre de', 'documento' => 'Cédula o NIT']],
        'breb' => ['Bre-B', ['llave' => 'Llave Bre-B', 'titular' => 'A nombre de']],
    ];

    // Lo mínimo para ofrecer cada medio.
    private const OBLIGATORIOS = ['nequi' => ['numero', 'titular'], 'bancolombia' => ['tipo', 'numero', 'titular'], 'breb' => ['llave', 'titular']];

    /** ¿Se cobra en línea por transferencia directa? (sin Wompi y con al menos un medio configurado). */
    public function activo(): bool
    {
        return ! app(PasarelaPagoService::class)->activa() && $this->mediosDisponibles() !== [];
    }

    /** @return array<string, array<string, string>> los datos guardados, medio => campo => valor */
    public function datos(): array
    {
        $datos = [];
        foreach (self::MEDIOS as $medio => [, $campos]) {
            foreach (array_keys($campos) as $campo) {
                $datos[$medio][$campo] = (string) Configuracion::obtener($this->clave($medio, $campo), '');
            }
        }

        return $datos;
    }

    /** @return array<int, string> */
    public function mediosDisponibles(): array
    {
        $datos = $this->datos();

        return array_values(array_filter(array_keys(self::MEDIOS),
            fn ($m) => collect(self::OBLIGATORIOS[$m])->every(fn ($c) => trim($datos[$m][$c]) !== '')));
    }

    public function medioDisponible(string $medio): bool
    {
        return in_array($medio, $this->mediosDisponibles(), true);
    }

    /** Lo que ve cualquiera en GET /configuracion: solo los medios listos para usarse. */
    public function publico(): array
    {
        $datos = $this->datos();

        return [
            'activo' => $this->activo(),
            // Con Wompi activo el pago en línea es automático (sin datos ni captura).
            'automatico' => app(PasarelaPagoService::class)->activa(),
            'medios' => array_map(fn ($medio) => [
                'medio' => $medio,
                'nombre' => self::MEDIOS[$medio][0],
                'campos' => array_values(array_filter(array_map(fn ($campo, $etiqueta) => [
                    'campo' => $campo,
                    'etiqueta' => $etiqueta,
                    'valor' => $campo === 'tipo' ? ucfirst($datos[$medio][$campo]) : $datos[$medio][$campo],
                    // Se copian los datos que se escriben en la app del banco.
                    'copiar' => in_array($campo, ['numero', 'llave', 'documento'], true),
                ], array_keys(self::MEDIOS[$medio][1]), self::MEDIOS[$medio][1]), fn ($c) => $c['valor'] !== '')),
            ], $this->mediosDisponibles()),
        ];
    }

    /** Lo guarda el SuperAdmin (PUT /negocio/pagos-directos). Vacío = el medio deja de ofrecerse. */
    public function actualizar(array $valores, User $actor): array
    {
        $antes = $this->datos();
        foreach (self::MEDIOS as $medio => [, $campos]) {
            foreach (array_keys($campos) as $campo) {
                if (array_key_exists($medio, $valores) && is_array($valores[$medio]) && array_key_exists($campo, $valores[$medio])) {
                    Configuracion::fijar($this->clave($medio, $campo), trim((string) ($valores[$medio][$campo] ?? '')));
                }
            }
        }
        $despues = $this->datos();

        $cambios = [];
        foreach ($despues as $medio => $campos) {
            foreach ($campos as $campo => $valor) {
                if ($antes[$medio][$campo] !== $valor) {
                    $cambios["{$medio}.{$campo}"] = ['antes' => $antes[$medio][$campo], 'despues' => $valor];
                }
            }
        }
        if ($cambios) {
            app(AuditoriaService::class)->registrar('editar_pagos_directos', 'Configuracion', null, ['cambios' => $cambios], $actor);
        }

        return $despues;
    }

    /** @return array{path: string, mime: string} */
    public function guardarComprobante(UploadedFile $archivo, string $prefijo): array
    {
        $extension = strtolower($archivo->guessExtension() ?: $archivo->getClientOriginalExtension() ?: 'bin');
        $path = $archivo->storeAs(self::CARPETA.'/'.now()->format('Y-m'), $prefijo.'-'.Str::random(16).'.'.$extension, self::DISCO);

        return ['path' => $path, 'mime' => (string) ($archivo->getMimeType() ?: 'application/octet-stream')];
    }

    public function existe(?string $path): bool
    {
        return $path !== null && Storage::disk(self::DISCO)->exists($path);
    }

    public function respuesta(string $path, ?string $mime, string $nombre): StreamedResponse
    {
        return Storage::disk(self::DISCO)->response($path, $nombre.'.'.pathinfo($path, PATHINFO_EXTENSION),
            ['Content-Type' => $mime ?: 'application/octet-stream', 'Cache-Control' => 'private, no-store'], 'inline');
    }

    /** Reglas del archivo y la referencia que sube el Cliente (pedido y depósito). */
    public static function reglasComprobante(): array
    {
        return [
            'comprobante' => [\Illuminate\Validation\Rule::requiredIf(fn () => app(self::class)->activo()), 'nullable', 'file',
                'mimes:jpg,jpeg,png,webp,pdf', 'mimetypes:image/jpeg,image/png,image/webp,application/pdf', 'max:5120'],
            'referencia' => ['nullable', 'string', 'max:60'],
        ];
    }

    public static function mensajesComprobante(): array
    {
        return [
            'comprobante.required' => 'Sube la captura o el PDF del comprobante de la transferencia.',
            'comprobante.mimes' => 'El comprobante debe ser una foto (JPG, PNG o WebP) o un PDF.',
            'comprobante.mimetypes' => 'El comprobante debe ser una foto (JPG, PNG o WebP) o un PDF.',
            'comprobante.max' => 'El comprobante no puede pesar más de 5 MB.',
        ];
    }

    /** Sin Wompi, solo los medios configurados; con Wompi, Bre-B no se ofrece. Devuelve el error o null. */
    public function errorDeMedio(string $medio): ?string
    {
        if (app(PasarelaPagoService::class)->activa()) {
            return $medio === 'breb' ? 'Bre-B no está disponible con el pago en línea automático.' : null;
        }

        return $this->medioDisponible($medio) ? null : 'Ese medio de pago no está disponible. Elige otro o paga al recibir.';
    }

    private function clave(string $medio, string $campo): string
    {
        return "pago_{$medio}_{$campo}";
    }
}
