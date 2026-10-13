<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 2, tarea 3 del plan de implementación (12/10/2026).
// RF-M04-010/011, HU-M04-004: el Administrador asocia extras GENERALES (reutilizables) a los
// productos que los ofrecen. Regla de compatibilidad con lo ya cargado: un extra general sin
// ningún producto asociado se sigue ofreciendo con todo el menú (como hasta hoy); en cuanto se
// asocia a uno o más productos, solo se ofrece con esos. Los extras específicos no cambian
// (RN-M04-05: siguen atados a su producto por extras.producto_id).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('producto_extra', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $table->foreignId('extra_id')->constrained('extras')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['producto_id', 'extra_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('producto_extra');
    }
};
