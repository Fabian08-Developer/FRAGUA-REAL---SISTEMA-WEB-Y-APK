<?php

namespace App\Services;

use App\Models\CompraGasto;
use App\Models\User;
use RuntimeException;

/**
 * M03 — Compras, Gastos y Cierre Diario.
 *
 * El Documento Técnico de Arquitectura no incluía un Service para M03 aunque ya existía
 * un CompraGastoController entregado — este Service llena ese vacío para que la regla
 * RN-M03-01 (valor > 0) y RN-M03-04 (anulación, no borrado) no queden repetidas en el
 * Controller.
 */
class CompraGastoService
{
    public function __construct(private readonly CajaService $cajaService)
    {
    }

    public function registrar(array $data, User $actor): CompraGasto
    {
        $valor = (float) $data['valor'];
        if ($valor <= 0) {
            throw new RuntimeException('El valor de la compra/gasto debe ser mayor a 0 (RN-M03-01).');
        }

        $compraGasto = new CompraGasto();
        $compraGasto->tipo = $data['tipo']; // compra | gasto
        $compraGasto->categoria = $data['categoria'] ?? null;
        $compraGasto->descripcion = $data['descripcion'];
        $compraGasto->valor = $valor;
        $compraGasto->metodo_pago = $data['metodo_pago'];
        $compraGasto->proveedor = $data['proveedor'] ?? null;
        $compraGasto->numero_factura = $data['numero_factura'] ?? null;
        $compraGasto->soporte_url = $data['soporte_url'] ?? null;
        $compraGasto->fecha = $data['fecha'];
        $compraGasto->registrado_por = $actor->id;
        $compraGasto->estado = 'activo';
        $compraGasto->save();

        // RF-M06-013: un gasto en efectivo sale de la caja del día.
        $this->cajaService->recalcularSiEstaAbierto($compraGasto->fecha->toDateString());

        return $compraGasto;
    }

    // RF-M03-14: editar un registro activo (no anulado) — sigue sin poder borrarse
    // (RN-M03-04); anular() es el único camino para "quitar" un registro definitivamente.
    public function editar(CompraGasto $compraGasto, array $data): CompraGasto
    {
        if (! $compraGasto->estaActivo()) {
            throw new RuntimeException('No se puede editar una compra/gasto ya anulada.');
        }

        if (array_key_exists('valor', $data) && (float) $data['valor'] <= 0) {
            throw new RuntimeException('El valor de la compra/gasto debe ser mayor a 0 (RN-M03-01).');
        }

        $fechaAnterior = $compraGasto->fecha?->toDateString();

        $compraGasto->fill(array_intersect_key($data, array_flip([
            'tipo', 'categoria', 'descripcion', 'valor', 'metodo_pago',
            'proveedor', 'numero_factura', 'soporte_url', 'fecha',
        ])));
        $compraGasto->save();

        // El valor, el método o la fecha pueden haber cambiado: se recalcula la caja de la
        // fecha anterior y la de la nueva (RF-M06-013).
        if ($fechaAnterior) {
            $this->cajaService->recalcularSiEstaAbierto($fechaAnterior);
        }
        $this->cajaService->recalcularSiEstaAbierto($compraGasto->fecha->toDateString());

        return $compraGasto;
    }

    // RN-M03-04: no se borra un registro de compra/gasto, se anula con motivo.
    public function anular(CompraGasto $compraGasto, string $motivo): CompraGasto
    {
        if (! $compraGasto->estaActivo()) {
            throw new RuntimeException('Esta compra/gasto ya está anulada.');
        }

        $compraGasto->estado = 'anulado';
        $compraGasto->anulado_motivo = $motivo;
        $compraGasto->save();

        $this->cajaService->recalcularSiEstaAbierto($compraGasto->fecha->toDateString());

        return $compraGasto;
    }

    public function totalPorTipoYFecha(string $tipo, \DateTimeInterface $desde, \DateTimeInterface $hasta): float
    {
        return (float) CompraGasto::where('tipo', $tipo)
            ->where('estado', 'activo')
            ->whereBetween('fecha', [$desde->format('Y-m-d'), $hasta->format('Y-m-d')])
            ->sum('valor');
    }
}
