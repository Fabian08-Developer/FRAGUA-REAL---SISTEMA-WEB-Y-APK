<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 1, tarea 1 del plan de implementación (10/10/2026).
// RF-M02-L-017, RN-PED-12, RF-M07-028, RF-M07-R-016: cada cambio a las líneas de un pedido
// (agregar, cambiar cantidad u observaciones, retirar) deja un registro con quién, cuándo,
// qué había antes y qué quedó, y cómo cambió el total.
// RN-PED-09: `afecta_comanda` marca los cambios hechos con el pedido ya CONFIRMADO, que son
// los que la comanda de actualización tendrá que imprimir cuando exista la impresión POS.
// Tabla nueva, sin tocar las existentes.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedido_modificaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_id')->constrained('pedidos')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // agregar_linea | cambiar_linea | retirar_linea
            $table->string('accion', 30);
            // Sin llave foránea a propósito: una línea retirada se borra, y su registro se queda.
            $table->unsignedBigInteger('pedido_detalle_id')->nullable();
            $table->string('estado_pedido', 20);
            $table->string('descripcion');
            $table->json('antes')->nullable();
            $table->json('despues')->nullable();
            $table->decimal('total_anterior', 10, 2);
            $table->decimal('total_nuevo', 10, 2);
            $table->boolean('afecta_comanda')->default(false);
            $table->timestamps();

            $table->index(['pedido_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedido_modificaciones');
    }
};
