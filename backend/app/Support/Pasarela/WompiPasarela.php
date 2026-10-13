<?php

namespace App\Support\Pasarela;

use App\Models\TransaccionPasarela;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

/**
 * Fase 6, tarea 1 (decisión D4 del 13/10/2026): adaptador de Wompi (Bancolombia). Un solo contrato
 * cubre Nequi, el botón Bancolombia, PSE y tarjetas.
 *
 *  - Cobro: Web Checkout de Wompi. Se manda al Cliente a checkout.wompi.co con el monto, la
 *    referencia y la firma de integridad (sha256 de referencia + monto en centavos + moneda +
 *    vencimiento + secreto de integridad), para que nadie pueda cambiar el monto en el enlace.
 *  - Confirmación: el evento "transaction.updated" que Wompi manda a /api/pasarela/wompi/eventos,
 *    firmado con el secreto de eventos (sha256 de los valores de signature.properties + timestamp
 *    + secreto). Además, la página de retorno y el proceso de conciliación consultan la transacción.
 *  - Reembolso: Wompi solo anula por API los pagos con tarjeta (POST /transactions/{id}/void). Los
 *    de Nequi, botón Bancolombia y PSE se devuelven desde el panel de Wompi o por transferencia:
 *    el sistema lo deja dicho como "no_soportado" para que el Administrador lo haga allá.
 *
 * Llaves en config/services.php ('wompi'), leídas del .env. Ambiente 'sandbox' o 'produccion'.
 */
class WompiPasarela implements PasarelaPago
{
    private const ESTADOS = [
        'APPROVED' => 'aprobado',
        'DECLINED' => 'rechazado',
        'VOIDED' => 'anulado',
        'ERROR' => 'error',
        'PENDING' => 'pendiente',
    ];

    public function nombre(): string
    {
        return 'wompi';
    }

    public function configurada(): bool
    {
        $c = config('services.wompi');

        return ! empty($c['llave_publica']) && ! empty($c['llave_privada'])
            && ! empty($c['secreto_eventos']) && ! empty($c['secreto_integridad']);
    }

    public function urlApi(): string
    {
        return config('services.wompi.ambiente') === 'produccion'
            ? 'https://production.wompi.co/v1'
            : 'https://sandbox.wompi.co/v1';
    }

    public function urlCheckout(TransaccionPasarela $transaccion, string $urlRetorno, array $pagador = []): string
    {
        $centavos = $transaccion->montoEnCentavos();
        $vence = $transaccion->expira_at?->copy()->utc()->format('Y-m-d\TH:i:s.v\Z');

        $parametros = [
            'public-key' => config('services.wompi.llave_publica'),
            'currency' => $transaccion->moneda,
            'amount-in-cents' => $centavos,
            'reference' => $transaccion->referencia,
            'signature:integrity' => $this->firmaDeIntegridad($transaccion->referencia, $centavos, $transaccion->moneda, $vence),
            'redirect-url' => $urlRetorno,
        ];
        if ($vence) {
            $parametros['expiration-time'] = $vence;
        }
        if (! empty($pagador['correo'])) {
            $parametros['customer-data:email'] = $pagador['correo'];
        }
        if (! empty($pagador['nombre'])) {
            $parametros['customer-data:full-name'] = $pagador['nombre'];
        }
        $telefono = preg_replace('/\D/', '', (string) ($pagador['telefono'] ?? ''));
        if (strlen($telefono) === 10) {
            $parametros['customer-data:phone-number'] = $telefono;
            $parametros['customer-data:phone-number-prefix'] = '+57';
        }

        return rtrim((string) config('services.wompi.url_checkout'), '?').'?'.http_build_query($parametros, '', '&', PHP_QUERY_RFC3986);
    }

    public function firmaDeIntegridad(string $referencia, int $centavos, string $moneda, ?string $vence = null): string
    {
        return hash('sha256', $referencia.$centavos.$moneda.($vence ?? '').config('services.wompi.secreto_integridad'));
    }

    public function consultar(string $transaccionId): ?ResultadoPasarela
    {
        // La consulta de una transacción por su id es pública en Wompi (llave pública).
        $respuesta = $this->llamar(fn () => $this->cliente(config('services.wompi.llave_publica'))
            ->get($this->urlApi().'/transactions/'.rawurlencode($transaccionId)));

        if ($respuesta->status() === 404) {
            return null;
        }
        $this->exigirExito($respuesta);

        return $this->resultado((array) $respuesta->json('data'));
    }

    public function buscarPorReferencia(string $referencia): ?ResultadoPasarela
    {
        // Buscar por referencia sí pide la llave privada.
        $respuesta = $this->llamar(fn () => $this->cliente(config('services.wompi.llave_privada'))
            ->get($this->urlApi().'/transactions', ['reference' => $referencia]));
        $this->exigirExito($respuesta);

        $transacciones = collect((array) $respuesta->json('data'))->filter(fn ($t) => ($t['reference'] ?? null) === $referencia);
        if ($transacciones->isEmpty()) {
            return null;
        }
        // Si alguna quedó aprobada, esa es la que cuenta; si no, la más reciente.
        $elegida = $transacciones->firstWhere('status', 'APPROVED')
            ?? $transacciones->sortByDesc(fn ($t) => $t['created_at'] ?? '')->first();

        return $this->resultado($elegida);
    }

    public function leerEvento(array $evento, array $encabezados = []): ?ResultadoPasarela
    {
        $firma = $evento['signature'] ?? null;
        $propiedades = $firma['properties'] ?? null;
        $checksum = strtolower((string) ($firma['checksum'] ?? ''));
        if (! is_array($propiedades) || $propiedades === [] || $checksum === '' || ! isset($evento['timestamp'])) {
            throw new FirmaInvalidaException('El evento no trae firma.');
        }

        $cadena = '';
        foreach ($propiedades as $propiedad) {
            $valor = Arr::get($evento['data'] ?? [], (string) $propiedad);
            if (is_array($valor)) {
                throw new FirmaInvalidaException('La firma del evento no es válida.');
            }
            $cadena .= is_bool($valor) ? ($valor ? 'true' : 'false') : (string) $valor;
        }
        $esperado = hash('sha256', $cadena.$evento['timestamp'].config('services.wompi.secreto_eventos'));

        if (! hash_equals($esperado, $checksum)) {
            throw new FirmaInvalidaException('La firma del evento no es válida.');
        }
        // Wompi manda también la firma en el encabezado; si viene, tiene que ser la misma.
        $encabezado = $encabezados['x-event-checksum'] ?? null;
        if ($encabezado !== null && $encabezado !== '' && ! hash_equals($esperado, strtolower((string) $encabezado))) {
            throw new FirmaInvalidaException('La firma del evento no es válida.');
        }

        if (($evento['event'] ?? null) !== 'transaction.updated' || ! isset($evento['data']['transaction'])) {
            return null;
        }

        return $this->resultado((array) $evento['data']['transaction'], $evento);
    }

    public function reembolsar(TransaccionPasarela $transaccion): ResultadoReembolso
    {
        if (! $transaccion->transaccion_id) {
            return new ResultadoReembolso('fallido', 'La transacción no tiene id en Wompi; no hay nada que devolver por la pasarela.');
        }
        if ($transaccion->metodo_pago !== 'CARD') {
            $medio = ['NEQUI' => 'Nequi', 'BANCOLOMBIA_TRANSFER' => 'el botón Bancolombia', 'PSE' => 'PSE', 'BANCOLOMBIA_QR' => 'QR Bancolombia'][$transaccion->metodo_pago] ?? 'este medio';

            return new ResultadoReembolso('no_soportado', "Wompi no devuelve por API los pagos hechos con {$medio}: haz la devolución desde el panel de Wompi o por transferencia, y deja aquí la constancia.");
        }

        try {
            $respuesta = $this->cliente(config('services.wompi.llave_privada'))
                ->post($this->urlApi().'/transactions/'.rawurlencode($transaccion->transaccion_id).'/void');
        } catch (ConnectionException) {
            return new ResultadoReembolso('fallido', 'Wompi no respondió. Vuelve a intentar el reembolso en unos minutos.');
        }

        if (! $respuesta->successful()) {
            $mensaje = (string) ($respuesta->json('error.messages.0') ?? $respuesta->json('error.reason') ?? $respuesta->json('error.type') ?? 'error '.$respuesta->status());

            return new ResultadoReembolso('fallido', "Wompi no aceptó el reembolso: {$mensaje}", null, (array) $respuesta->json());
        }

        $datos = (array) $respuesta->json('data');

        return new ResultadoReembolso(
            ($datos['status'] ?? null) === 'APPROVED' || ($datos['transaction']['status'] ?? null) === 'VOIDED' ? 'aprobado' : 'solicitado',
            'Wompi recibió la anulación del pago con tarjeta.',
            isset($datos['id']) ? (string) $datos['id'] : null,
            $datos,
        );
    }

    // ───────────── internos ─────────────

    private function cliente(?string $llave): PendingRequest
    {
        return Http::acceptJson()->withToken((string) $llave)->timeout(15)->connectTimeout(5);
    }

    private function llamar(callable $peticion): Response
    {
        try {
            return $peticion();
        } catch (ConnectionException) {
            throw new PasarelaNoDisponibleException('Wompi no respondió. Intenta de nuevo en unos minutos.');
        }
    }

    private function exigirExito(Response $respuesta): void
    {
        if (! $respuesta->successful()) {
            throw new PasarelaNoDisponibleException('Wompi respondió con un error ('.$respuesta->status().'). Intenta de nuevo en unos minutos.');
        }
    }

    private function resultado(array $t, ?array $evento = null): ResultadoPasarela
    {
        $estado = strtoupper((string) ($t['status'] ?? ''));

        return new ResultadoPasarela(
            self::ESTADOS[$estado] ?? 'pendiente',
            isset($t['id']) ? (string) $t['id'] : null,
            $t['reference'] ?? null,
            isset($t['amount_in_cents']) ? (int) $t['amount_in_cents'] : null,
            $t['currency'] ?? 'COP',
            $t['payment_method_type'] ?? null,
            $estado ?: null,
            $evento ?? $t,
        );
    }
}
