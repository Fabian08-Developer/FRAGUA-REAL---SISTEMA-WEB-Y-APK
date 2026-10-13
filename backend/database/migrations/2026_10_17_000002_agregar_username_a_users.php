<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3, tarea 3 (12/10/2026) — RF-M01-007, RF-M01-011, RNF-M01-004, RN-USR-04: iniciar sesión
 * con el correo o con un nombre de usuario, único en todo el sistema.
 *
 * Aditiva: la columna es opcional, así que las cuentas existentes quedan sin nombre de usuario y
 * siguen entrando con su correo. Se guarda siempre en minúsculas (App\Support\Usuarios\NombreUsuario).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 30)->nullable()->unique()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }
};
