<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3, tarea 4 (12/10/2026) — RF-M01-010, RF-M01-024, HU-M01-010: cada sesión (token de
 * Sanctum) guarda desde qué IP y con qué navegador se abrió, para que la persona y el
 * Administrador la reconozcan en la lista de sesiones abiertas.
 *
 * Aditiva: las sesiones que ya estaban abiertas quedan sin IP ni navegador.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('ip', 45)->nullable()->after('abilities');
            $table->string('user_agent')->nullable()->after('ip');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn(['ip', 'user_agent']);
        });
    }
};
