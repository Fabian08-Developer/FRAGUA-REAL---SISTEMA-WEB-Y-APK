<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('combos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->decimal('precio', 10, 2); // RN-M04-06: manual, no calculado
            $table->enum('tipo_venta', ['bebida', 'alimento', 'otro']); // RF-M04-043, elegido por el Admin
            $table->date('vigencia_inicio')->nullable();
            $table->date('vigencia_fin')->nullable();
            $table->time('franja_inicio')->nullable();
            $table->time('franja_fin')->nullable();
            $table->json('dias_semana')->nullable(); // ej. [0,6] = domingo y sábado
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('combos');
    }
};
