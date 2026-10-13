<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promociones', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->enum('tipo', ['automatica', 'manual']);
            $table->enum('alcance', ['producto', 'categoria', 'total']);
            $table->enum('tipo_valor', ['porcentaje', 'fijo']);
            $table->decimal('valor', 10, 2); // RN-M04-23: > 0 y <= 100 si es porcentaje
            $table->date('vigencia_inicio')->nullable();
            $table->date('vigencia_fin')->nullable();
            $table->time('franja_inicio')->nullable();
            $table->time('franja_fin')->nullable();
            $table->json('dias_semana')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promociones');
    }
};
