<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 0, tarea 3 del plan de implementación (09/10/2026).
// - RF-M06-017: el Administrador anula O reembolsa un pago; se guarda cuál de las dos fue.
// - RF-M06-019, RN-M06-10: anular un pago de un día con la caja ya cerrada exige autorización
//   adicional y queda como AJUSTE EXTRAORDINARIO, sin reescribir las cifras del turno cerrado.
// Migración aditiva: los pagos existentes quedan con reverso_tipo NULL (o 'anulacion' si ya
// estaban anulados, para que el historial se lea igual).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pagos', function (Blueprint $table) {
            $table->string('reverso_tipo', 20)->nullable()->after('anulado'); // anulacion | reembolso
        });

        \Illuminate\Support\Facades\DB::table('pagos')->where('anulado', true)->update(['reverso_tipo' => 'anulacion']);

        Schema::create('ajustes_caja', function (Blueprint $table) {
            $table->id();
            $table->foreignId('caja_turno_id')->constrained('caja_turnos')->restrictOnDelete();
            $table->foreignId('pago_id')->constrained('pagos')->restrictOnDelete();
            $table->string('tipo', 20); // anulacion | reembolso
            $table->string('metodo', 20); // método del pago reversado
            $table->decimal('monto', 10, 2);
            $table->string('motivo');
            $table->foreignId('autorizado_por')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ajustes_caja');
        Schema::table('pagos', function (Blueprint $table) {
            $table->dropColumn('reverso_tipo');
        });
    }
};
