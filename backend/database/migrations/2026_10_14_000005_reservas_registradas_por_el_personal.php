<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fase 1, tarea 4 del plan de implementación (11/10/2026).
// RF-M05-013, RN-M05-06: una reserva manual la registra el Mesero O el Administrador. Antes
// `creado_por` solo aceptaba cliente_web|mesero, y como POST /reservas es una ruta pública el
// controlador nunca veía la sesión: toda reserva quedaba como "cliente_web", también las del
// personal. Ahora acepta "administrador" y `registrada_por` guarda qué persona la registró.
// Aditiva: las reservas existentes no cambian.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE reservas DROP CONSTRAINT IF EXISTS reservas_creado_por_check');
            DB::statement("ALTER TABLE reservas ADD CONSTRAINT reservas_creado_por_check CHECK (creado_por IN ('cliente_web', 'mesero', 'administrador'))");
        } else {
            Schema::table('reservas', function (Blueprint $table) {
                $table->enum('creado_por', ['cliente_web', 'mesero', 'administrador'])->change();
            });
        }

        Schema::table('reservas', function (Blueprint $table) {
            $table->foreignId('registrada_por')->nullable()->after('creado_por')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reservas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('registrada_por');
        });

        DB::table('reservas')->where('creado_por', 'administrador')->update(['creado_por' => 'mesero']);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE reservas DROP CONSTRAINT IF EXISTS reservas_creado_por_check');
            DB::statement("ALTER TABLE reservas ADD CONSTRAINT reservas_creado_por_check CHECK (creado_por IN ('cliente_web', 'mesero'))");
        } else {
            Schema::table('reservas', function (Blueprint $table) {
                $table->enum('creado_por', ['cliente_web', 'mesero'])->change();
            });
        }
    }
};
