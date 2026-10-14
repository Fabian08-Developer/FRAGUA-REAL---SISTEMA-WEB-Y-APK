<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 7, tarea 5 (13/10/2026): reportes de un mes completo a buena velocidad (RNF-M08-001).
 *
 * Con un año de historia de prueba (unos 110.000 pedidos y 330.000 líneas), «Ventas por producto»
 * de 30 días tardaba más de 2 minutos y «Ventas por tipo y método» más de 1 minuto. PostgreSQL no
 * crea índices para las llaves foráneas: cada línea vendida recorría la tabla entera de extras
 * para sumar los suyos, y cada reporte leía todos los pedidos para quedarse con los del periodo.
 *
 * Estos índices cubren lo que leen los reportes (pedidos finalizados por fecha, líneas y extras de
 * cada pedido, pagos por fecha, reservas por fecha) y las búsquedas de todos los días que crecen con
 * el tiempo (pedidos de una mesa o de un cliente, pagos de un pedido o de un turno de caja,
 * reservas de una mesa). No cambian ningún dato.
 */
return new class extends Migration
{
    private const INDICES = [
        'pedidos' => [
            'pedidos_estado_finalizado_at_index' => ['estado', 'finalizado_at'],
            'pedidos_created_at_index' => ['created_at'],
            'pedidos_mesa_id_estado_index' => ['mesa_id', 'estado'],
            'pedidos_cliente_id_index' => ['cliente_id'],
        ],
        'pedido_detalles' => [
            'pedido_detalles_pedido_id_index' => ['pedido_id'],
        ],
        'pedido_detalle_extra' => [
            'pedido_detalle_extra_pedido_detalle_id_index' => ['pedido_detalle_id'],
        ],
        'pagos' => [
            'pagos_pedido_id_index' => ['pedido_id'],
            'pagos_caja_turno_id_index' => ['caja_turno_id'],
            'pagos_created_at_index' => ['created_at'],
        ],
        'reservas' => [
            'reservas_mesa_id_fecha_hora_index' => ['mesa_id', 'fecha_hora'],
            'reservas_fecha_hora_index' => ['fecha_hora'],
        ],
        'ajustes_caja' => [
            'ajustes_caja_caja_turno_id_index' => ['caja_turno_id'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDICES as $tabla => $indices) {
            Schema::table($tabla, function (Blueprint $table) use ($indices) {
                foreach ($indices as $nombre => $columnas) {
                    $table->index($columnas, $nombre);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::INDICES as $tabla => $indices) {
            Schema::table($tabla, function (Blueprint $table) use ($indices) {
                foreach (array_keys($indices) as $nombre) {
                    $table->dropIndex($nombre);
                }
            });
        }
    }
};
