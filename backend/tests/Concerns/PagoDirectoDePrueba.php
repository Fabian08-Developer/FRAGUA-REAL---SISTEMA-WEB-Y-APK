<?php

namespace Tests\Concerns;

use App\Models\Configuracion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/**
 * Pago directo (22/10/2026): sin Wompi, pagar en línea exige un medio configurado por el SuperAdmin y
 * la captura del comprobante. Las pruebas que pagan en línea usan esto: deja Nequi y Bancolombia
 * configurados (una sola vez, con el disco de archivos de mentira) y envía el pago con una captura.
 */
trait PagoDirectoDePrueba
{
    private bool $pagoDirectoListo = false;

    protected function configurarPagoDirecto(): void
    {
        if ($this->pagoDirectoListo) {
            return;
        }
        Storage::fake('local');
        foreach ([
            'pago_nequi_numero' => '3001234567', 'pago_nequi_titular' => 'Fragua Real Burger',
            'pago_bancolombia_tipo' => 'ahorros', 'pago_bancolombia_numero' => '12345678901',
            'pago_bancolombia_titular' => 'Fragua Real Burger', 'pago_bancolombia_documento' => '1075320008',
            'pago_breb_llave' => '@fraguareal', 'pago_breb_titular' => 'Fragua Real Burger',
        ] as $clave => $valor) {
            Configuracion::fijar($clave, $valor);
        }
        $this->pagoDirectoListo = true;
    }

    protected function captura(string $nombre = 'comprobante.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($nombre, 600, 900);
    }

    /** POST de un pago o un depósito en línea, con la captura del comprobante. */
    protected function pagoDirecto(string $uri, array $datos = [], ?UploadedFile $captura = null): TestResponse
    {
        $this->configurarPagoDirecto();

        return $this->post($uri, $datos + ['comprobante' => $captura ?? $this->captura()], ['Accept' => 'application/json']);
    }
}
