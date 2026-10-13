<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('caja_turnos', function (Blueprint $table) {
            $table->id();
            $table->date('fecha')->unique(); // RN-M06-03: un solo turno por día
            $table->decimal('base_inicial', 10, 2);
            $table->decimal('efectivo_esperado', 10, 2)->default(0);
            $table->decimal('efectivo_contado', 10, 2)->nullable();
            $table->decimal('diferencia', 10, 2)->nullable();
            $table->text('justificacion_descuadre')->nullable();
            $table->foreignId('abierto_por')->constrained('users')->restrictOnDelete();
            $table->dateTime('abierto_at');
            $table->foreignId('cerrado_por')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('cerrado_at')->nullable();
            $table->enum('estado', ['abierta', 'cerrada'])->default('abierta');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caja_turnos');
    }
};
