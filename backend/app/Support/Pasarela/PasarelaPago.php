<?php

namespace App\Support\Pasarela;

use App\Models\TransaccionPasarela;

/**
 * Fase 6, tarea 1 (RF-M06-006, decisión D4): lo que el sistema necesita de una pasarela de pago.
 * Hoy la implementa WompiPasarela; si mañana se cambia de proveedor, se escribe otro adaptador y
 * el resto (PasarelaPagoService, PagoService, ReservaService) no cambia.
 *
 * Los estados vuelven siempre normalizados: pendiente | aprobado | rechazado | anulado | error.
 */
interface PasarelaPago
{
    /** Nombre corto del proveedor ('wompi'), el que queda en transacciones_pasarela.proveedor. */
    public function nombre(): string;

    /** ¿Tiene todas las llaves para cobrar y verificar eventos? */
    public function configurada(): bool;

    /**
     * El enlace al que se manda al Cliente para pagar. $pagador lleva correo, nombre y teléfono
     * para que la pasarela los traiga ya llenos (todos opcionales).
     */
    public function urlCheckout(TransaccionPasarela $transaccion, string $urlRetorno, array $pagador = []): string;

    /** La transacción con ese id, tal como la ve la pasarela ahora; null si no existe. */
    public function consultar(string $transaccionId): ?ResultadoPasarela;

    /** La transacción hecha con esa referencia (la aprobada, si hay; si no, la última); null si no hay ninguna. */
    public function buscarPorReferencia(string $referencia): ?ResultadoPasarela;

    /**
     * Lee un evento enviado por la pasarela. Lanza FirmaInvalidaException si la firma no
     * corresponde; devuelve null si el evento no es de una transacción (no hay nada que hacer).
     */
    public function leerEvento(array $evento, array $encabezados = []): ?ResultadoPasarela;

    /** Pide a la pasarela devolver el dinero de una transacción aprobada. */
    public function reembolsar(TransaccionPasarela $transaccion): ResultadoReembolso;
}
