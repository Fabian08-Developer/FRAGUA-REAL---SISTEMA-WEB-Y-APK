<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuraciones', function (Blueprint $table) {
            $table->id();
            // RF-M07-030 / RN-M05-08 a 13: valores del sistema que el Administrador debe
            // poder editar "en cualquier momento" sin tocar código ni redesplegar — hoy solo
            // valor_domicilio (M07) y deposito_reserva (M05), pero la tabla queda genérica
            // (clave/valor) para no migrar de nuevo si aparece un tercer valor editable.
            $table->string('clave')->unique();
            $table->string('valor');
            $table->string('descripcion')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuraciones');
    }
};
