<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pagos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_id')->constrained('pedidos')->cascadeOnDelete();
            $table->foreignId('caja_turno_id')->nullable()->constrained('caja_turnos')->restrictOnDelete();
            $table->enum('metodo', ['efectivo', 'tarjeta', 'transferencia', 'en_linea']);
            $table->enum('pasarela', ['nequi', 'bancolombia'])->nullable(); // solo si metodo = en_linea
            $table->decimal('monto', 10, 2);
            $table->enum('estado', ['pendiente', 'aprobado', 'rechazado'])->default('pendiente');
            // null si el Cliente paga en línea directamente (sin que un empleado lo registre)
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('anulado')->default(false);
            $table->string('anulado_motivo')->nullable();
            $table->foreignId('anulado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('anulado_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagos');
    }
};
