<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 5, tarea 2 (13/10/2026) — RF-M03-28/29, RNF-M03-09/10, HU-M03-11/12: cierre diario en PDF.
 * Cuántas veces se imprimió cada cierre, para marcar las reimpresiones (misma regla que el
 * comprobante, decisión del usuario del 13/10/2026). Aditiva: los cierres existentes quedan en 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cierres_diarios', function (Blueprint $table) {
            $table->unsignedInteger('impresiones')->default(0)->after('caja_turno_id');
        });
    }

    public function down(): void
    {
        Schema::table('cierres_diarios', function (Blueprint $table) {
            $table->dropColumn('impresiones');
        });
    }
};
