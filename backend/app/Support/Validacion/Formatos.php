<?php

namespace App\Support\Validacion;

/**
 * Formatos de los campos (22/10/2026): las mismas reglas que aplica la pantalla mientras se escribe
 * (frontend/src/utils/formatos.ts), para que nada mal escrito llegue a la base aunque se salten la
 * pantalla. Los valores se normalizan antes de validar (un teléfono «300 123 4567» o «+57 300…» se
 * guarda como «3001234567»; un NIT «900.123.456-7», como «900123456-7»).
 */
final class Formatos
{
    /** Celular (3xx) o fijo (60x) de Colombia: 10 dígitos. */
    public const CELULAR = '/^(3\d{9}|60\d{8})$/';

    public const MENSAJE_CELULAR = 'Escribe un teléfono de 10 dígitos, solo con números (celular 3xx o fijo 60x).';

    /** Nombre de una persona o titular: letras (con tildes y ñ), espacios, punto, apóstrofo y guion. */
    public const PERSONA = "/^[\\p{L}\\p{M}][\\p{L}\\p{M} .'\\-]*$/u";

    public const MENSAJE_PERSONA = 'El nombre solo puede llevar letras, espacios, punto, apóstrofo o guion (sin números).';

    /** Cédula (5 a 10 dígitos) o NIT con su dígito de verificación (900123456-7). */
    public const DOCUMENTO = '/^\d{5,10}(-\d)?$/';

    public const MENSAJE_DOCUMENTO = 'Escribe la cédula o el NIT solo con números y, si lleva, el guion del dígito de verificación (por ejemplo, 900123456-7).';

    /** Cuenta de ahorros o corriente de Bancolombia: 11 dígitos. */
    public const CUENTA_BANCOLOMBIA = '/^\d{11}$/';

    public const MENSAJE_CUENTA = 'El número de cuenta Bancolombia tiene 11 dígitos, solo números.';

    /** Llave Bre-B: @alias, celular, cédula o NIT, o correo. Sin espacios. */
    public const LLAVE_BREB = '/^(@[A-Za-z0-9._-]{2,30}|3\d{9}|\d{5,10}(-\d)?|[A-Za-z0-9._+-]+@[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)+)$/';

    public const MENSAJE_LLAVE = 'La llave Bre-B debe ser un @alias, un celular de 10 dígitos, una cédula o NIT, o un correo, sin espacios.';

    /** Tope de cualquier valor en pesos que se escribe a mano. */
    public const MAX_PESOS = 99999999;

    /** «+57 300 123-4567» → «3001234567». Vacío → null. Lo que no es texto se deja igual. */
    public static function telefono(mixed $valor): mixed
    {
        if (! is_string($valor)) {
            return $valor;
        }
        $digitos = preg_replace('/\D+/', '', $valor);
        if (strlen($digitos) === 12 && str_starts_with($digitos, '57')) {
            $digitos = substr($digitos, 2);
        }

        return $digitos === '' ? (trim($valor) === '' ? null : $valor) : $digitos;
    }

    /** «900.123.456 - 7» → «900123456-7». Vacío → null. */
    public static function documento(mixed $valor): mixed
    {
        if (! is_string($valor)) {
            return $valor;
        }
        $limpio = preg_replace('/[\s.]+/', '', $valor);

        return $limpio === '' ? null : $limpio;
    }

    /** Solo los dígitos («123-456789-01» → «12345678901»). */
    public static function digitos(mixed $valor): mixed
    {
        if (! is_string($valor)) {
            return $valor;
        }
        $limpio = preg_replace('/[\s\-]+/', '', $valor);

        return $limpio === '' ? null : $limpio;
    }

    /** Espacios de más dentro de un nombre: «Ana   María » → «Ana María». */
    public static function nombre(mixed $valor): mixed
    {
        return is_string($valor) ? trim(preg_replace('/\s+/u', ' ', $valor)) : $valor;
    }

    /** Normaliza en el request los campos dados con la función dada (sin crear los que no vinieron). */
    public static function normalizar(\Illuminate\Http\Request $request, array $campos): void
    {
        foreach ($campos as $campo => $funcion) {
            if ($request->has($campo)) {
                $datos = $request->all();
                data_set($datos, $campo, self::{$funcion}(data_get($datos, $campo)));
                $request->merge([explode('.', $campo)[0] => data_get($datos, explode('.', $campo)[0])]);
            }
        }
    }
}
