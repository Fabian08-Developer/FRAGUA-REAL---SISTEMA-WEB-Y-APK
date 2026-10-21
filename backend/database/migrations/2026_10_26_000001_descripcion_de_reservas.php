<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Corrección (19/10/2026): el cliente puede dejar una descripción en su reserva (cumpleaños, silla
// para bebé, cerca de la ventana…). Opcional; la ve el personal en Reservas.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservas', function (Blueprint $table) {
            $table->string('descripcion', 500)->nullable()->after('cliente_correo');
        });
    }

    public function down(): void
    {
        Schema::table('reservas', function (Blueprint $table) {
            $table->dropColumn('descripcion');
        });
    }
};
