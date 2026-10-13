<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6, tarea 2 (13/10/2026, decisión D5: comandas impresas desde el navegador en V1).
 * RF-M02-L-011, RF-M02-T-004 a 007, RN-PED-06, 09, 17 a 19, RNF-M02-001, 004, 005, 011, 014, 016,
 * HU-M02-016, 017, RF-M07-020, 022.
 *
 * Una comanda es lo que la cocina recibe: la inicial al confirmar el pedido, una de actualización
 * por los cambios hechos ya confirmado (RN-PED-09) y una de cancelación si se cancela un pedido que
 * la cocina ya tenía. Su estado de impresión vive aquí, separado del estado del pedido (RN-PED-19,
 * RNF-M02-014): un fallo de impresión nunca toca el pedido (RN-PED-17).
 *
 * `clave` es la clave de idempotencia (RF-M02-T-007, RNF-M02-004): la misma confirmación o el mismo
 * cambio nunca generan dos comandas.
 *
 * Aditiva: no cambia filas existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comandas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_id')->constrained('pedidos')->cascadeOnDelete();
            $table->unsignedInteger('numero');                 // 1, 2, 3… dentro del pedido
            $table->string('tipo', 20);                       // inicial | actualizacion | cancelacion
            $table->string('clave', 80)->unique();            // idempotencia
            $table->json('contenido');                        // lo que se imprime, congelado
            // pendiente | impresa | fallida | descartada (pedido cancelado antes de imprimirla)
            $table->string('estado_impresion', 20)->default('pendiente');
            $table->unsignedInteger('intentos')->default(0);    // veces que se mandó a imprimir
            $table->unsignedInteger('impresiones')->default(0); // impresiones que salieron (regla de reimpresión)
            $table->dateTime('impresa_at')->nullable();
            $table->foreignId('impresa_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ultimo_error')->nullable();
            // Hasta qué cambio del historial del pedido lleva esta comanda (RN-PED-09). El historial
            // (`pedido_modificaciones`) no se edita, así que el enlace vive de este lado.
            $table->unsignedBigInteger('modificacion_hasta')->nullable();
            $table->timestamps();

            $table->unique(['pedido_id', 'numero']);
            $table->index(['estado_impresion', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comandas');
    }
};
