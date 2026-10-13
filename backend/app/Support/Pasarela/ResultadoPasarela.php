<?php

namespace App\Support\Pasarela;

/** Fase 6, tarea 1: una transacción como la ve la pasarela, ya con el estado normalizado. */
final class ResultadoPasarela
{
    public function __construct(
        public readonly string $estado,            // pendiente | aprobado | rechazado | anulado | error
        public readonly ?string $transaccionId,
        public readonly ?string $referencia,
        public readonly ?int $montoCentavos,
        public readonly ?string $moneda = 'COP',
        public readonly ?string $metodo = null,     // NEQUI, BANCOLOMBIA_TRANSFER, CARD, PSE…
        public readonly ?string $estadoProveedor = null,
        public readonly array $datos = [],          // la respuesta completa, para guardarla
    ) {
    }

    public function esFinal(): bool
    {
        return $this->estado !== 'pendiente';
    }
}
