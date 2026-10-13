<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 5, tarea 1b (13/10/2026) — reimpresión controlada del comprobante (decisión del usuario del
 * 13/10/2026): cuántas veces lo imprimió el personal. Desde la segunda, el documento sale marcado
 * "REIMPRESIÓN"; cada impresión queda en la auditoría con quién la pidió.
 * Aditiva: los comprobantes existentes quedan con 0 impresiones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comprobantes', function (Blueprint $table) {
            $table->unsignedInteger('impresiones')->default(0)->after('anulado_motivo');
        });
    }

    public function down(): void
    {
        Schema::table('comprobantes', function (Blueprint $table) {
            $table->dropColumn('impresiones');
        });
    }
};
