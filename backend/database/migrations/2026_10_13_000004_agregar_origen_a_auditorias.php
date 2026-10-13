<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 0, tarea 7 (09/10/2026): RF-M01-024 y RNF-TRANS-021 piden identificar el origen de
// cada acción registrada. Aditiva: los registros anteriores quedan sin IP ni navegador.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auditorias', function (Blueprint $table) {
            $table->string('ip', 45)->nullable()->after('detalles');
            $table->string('user_agent')->nullable()->after('ip');
            $table->index(['user_id', 'created_at']);
            $table->index('accion');
        });
    }

    public function down(): void
    {
        Schema::table('auditorias', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'created_at']);
            $table->dropIndex(['accion']);
            $table->dropColumn(['ip', 'user_agent']);
        });
    }
};
