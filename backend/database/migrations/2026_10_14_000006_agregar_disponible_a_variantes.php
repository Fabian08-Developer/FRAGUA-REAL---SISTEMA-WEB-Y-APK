<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 1, tarea 5 del plan de implementación (11/10/2026).
// RF-M04-016/017/018, RN-M04-07/08: un producto O UNA VARIANTE se marca agotado (por ejemplo, se
// acabó el pan grande pero la hamburguesa sencilla sigue). Antes la variante solo tenía `activa`
// (baja lógica del catálogo, del Administrador); `disponible` es la marca temporal que también
// cambia el Mesero. Aditiva: todas las variantes existentes quedan disponibles.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('variantes', function (Blueprint $table) {
            $table->boolean('disponible')->default(true)->after('activa');
        });
    }

    public function down(): void
    {
        Schema::table('variantes', function (Blueprint $table) {
            $table->dropColumn('disponible');
        });
    }
};
