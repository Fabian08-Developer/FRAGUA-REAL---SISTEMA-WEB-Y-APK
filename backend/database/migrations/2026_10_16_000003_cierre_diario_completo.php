<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 2, tarea 5 del plan de implementación (12/10/2026) — M03, cierre diario completo.
// - cierres_diarios.detalle: la foto del desglose al cerrar (ventas por tipo y por forma de pago,
//   compras y gastos por categoría y forma de pago, arqueo de caja), para consultarlo igual después
//   (RF-M03-22/23/24/27, RN-M03-15, RN-M06-11/12).
// - cierres_diarios.caja_turno_id: el arqueo del día que alimentó el cierre (RN-M06-11).
// - cierre_ajustes: correcciones autorizadas después del cierre (RF-M03-20, RN-M03-08, HU-M03-09).
//   El cierre no se reescribe (decisión D7): cada corrección queda como un ajuste con lo que cambió,
//   quién lo autorizó, cuándo y por qué, y la consulta muestra el cierre, sus ajustes y el valor
//   actualizado.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cierres_diarios', function (Blueprint $table) {
            $table->json('detalle')->nullable()->after('resultado_operativo');
            $table->foreignId('caja_turno_id')->nullable()->after('detalle')->constrained('caja_turnos')->nullOnDelete();
        });

        Schema::create('cierre_ajustes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cierre_diario_id')->constrained('cierres_diarios')->restrictOnDelete();
            $table->foreignId('compra_gasto_id')->nullable()->constrained('compras_gastos')->nullOnDelete();
            $table->string('accion', 30); // registrar | editar | anular
            $table->decimal('delta_compras', 10, 2)->default(0);
            $table->decimal('delta_gastos', 10, 2)->default(0);
            $table->json('detalle')->nullable();
            $table->string('motivo');
            $table->foreignId('autorizado_por')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cierre_ajustes');
        Schema::table('cierres_diarios', function (Blueprint $table) {
            $table->dropConstrainedForeignId('caja_turno_id');
            $table->dropColumn('detalle');
        });
    }
};
