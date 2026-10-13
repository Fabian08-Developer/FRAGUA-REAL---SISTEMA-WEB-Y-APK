<?php

namespace App\Support\Pasarela;

/** Fase 6, tarea 1 (RF-M06-017): lo que respondió la pasarela al pedirle un reembolso. */
final class ResultadoReembolso
{
    public function __construct(
        public readonly string $estado,      // solicitado | aprobado | no_soportado | fallido
        public readonly string $mensaje,
        public readonly ?string $id = null,
        public readonly array $datos = [],
    ) {
    }
}
