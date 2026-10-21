<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// M09 (20/10/2026): permisos de menú por cuenta. El SuperAdmin decide qué menús ve cada
// Administrador y cada Mesero. Vacío (null) = todos los menús de su rol, como hasta ahora.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('menus_permitidos')->nullable()->after('puede_registrar_gastos');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('menus_permitidos');
        });
    }
};
