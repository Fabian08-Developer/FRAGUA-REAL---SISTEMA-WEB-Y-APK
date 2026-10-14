<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 7, tarea 3 (13/10/2026): foto del producto subida desde Catálogo (RF-M04-004, HU-M04-002).
// El archivo queda en el almacenamiento del servidor (no en una carpeta pública) y se sirve por la
// API; `imagen_url` sigue siendo la dirección que usa la pantalla. Aditiva.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->string('imagen_path')->nullable()->after('imagen_url');
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->dropColumn('imagen_path');
        });
    }
};
