<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4, tarea 3 (13/10/2026) — RF-M05-014, 016, 024, RN-M05-07: el sistema libera solo las
 * reservas no honradas y avisa al cliente antes de su hora.
 *  - no_show_at / no_show_automatico: cuándo se marcó el no-show y si lo hizo el sistema al
 *    vencer la tolerancia o una persona.
 *  - recordatorio_enviado_at: cuándo salió el recordatorio, para no enviarlo dos veces.
 * Índice por estado y hora para que el proceso de cada minuto sea rápido. Aditiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservas', function (Blueprint $table) {
            $table->timestamp('no_show_at')->nullable()->after('llegada_at');
            $table->boolean('no_show_automatico')->default(false)->after('no_show_at');
            $table->timestamp('recordatorio_enviado_at')->nullable()->after('no_show_automatico');
            $table->index(['estado', 'fecha_hora']);
        });
    }

    public function down(): void
    {
        Schema::table('reservas', function (Blueprint $table) {
            $table->dropIndex(['estado', 'fecha_hora']);
            $table->dropColumn(['no_show_at', 'no_show_automatico', 'recordatorio_enviado_at']);
        });
    }
};
