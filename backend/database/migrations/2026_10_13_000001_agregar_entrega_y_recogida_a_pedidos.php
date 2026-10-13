<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 0, tarea 1 del plan de implementación (09/10/2026): el Mesero confirma la entrega de
// un domicilio (RF-M07-025) o la recogida en el local (RF-M07-R-013). Sin estas columnas el
// pedido se finalizaba solo con el pago, sin que nadie confirmara la entrega (RN-M07-06,
// RN-M07-R-04). Migración aditiva: los pedidos existentes quedan con NULL.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->foreignId('entregado_por')->nullable()->after('preparando_at')->constrained('users')->nullOnDelete();
            $table->dateTime('entregado_at')->nullable()->after('entregado_por');
            $table->foreignId('recogido_por')->nullable()->after('entregado_at')->constrained('users')->nullOnDelete();
            $table->dateTime('recogido_at')->nullable()->after('recogido_por');
        });
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('entregado_por');
            $table->dropColumn('entregado_at');
            $table->dropConstrainedForeignId('recogido_por');
            $table->dropColumn('recogido_at');
        });
    }
};
