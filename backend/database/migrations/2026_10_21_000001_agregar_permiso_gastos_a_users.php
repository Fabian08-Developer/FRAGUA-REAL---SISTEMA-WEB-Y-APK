<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 7, tarea 2 (13/10/2026): «usuario autorizado» de M03 (RNF-TRANS-012, RF-M03-32,
// HU-M03-01 a 03). El Administrador le da a un Mesero permiso para registrar compras y gastos.
// No es un rol nuevo: es una marca de la cuenta. Aditiva: nadie la tiene al migrar.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('puede_registrar_gastos')->default(false)->after('activo');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('puede_registrar_gastos');
        });
    }
};
