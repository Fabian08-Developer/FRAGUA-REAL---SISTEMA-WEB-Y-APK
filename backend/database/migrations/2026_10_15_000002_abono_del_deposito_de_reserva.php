<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 2, tarea 2 del plan de implementación (12/10/2026).
// RF-M05-034, RN-M05-19: cuando el cliente de una reserva llega, el depósito que pagó se abona
// a los pedidos de esa visita. Decisión D7 del plan: el abono es un PAGO de tipo "depósito"
// sobre el pedido (trazable, sin reescribir cierres); no entra a la caja del día de la visita,
// porque ese dinero ya entró el día en que se recibió el depósito.
//
// - pagos.tipo: cobro (lo de siempre) | deposito (abono del depósito de una reserva).
// - pagos.reserva_id: de qué reserva sale el abono.
// - reservas.pedido_id: primer pedido de la visita al que se abonó.
// - reservas.deposito_abonado: cuánto del depósito ya se abonó (puede quedar saldo sin usar si
//   la visita consumió menos que el depósito).
// - reservas.llegada_at: cuándo llegó el cliente (confirmar llegada o primer pedido en la mesa).
// - reservas.deposito_caja_turno_id / deposito_reembolso_caja_turno_id: turno de caja en que se
//   recibió un depósito manual y en que se devolvió, para que el efectivo esperado lo cuente
//   (RN-M06-08). Los depósitos en línea no pasan por la caja.
// Migración aditiva: los pagos existentes quedan como "cobro".
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pagos', function (Blueprint $table) {
            $table->string('tipo', 20)->default('cobro')->after('pedido_id');
            $table->foreignId('reserva_id')->nullable()->after('tipo')->constrained('reservas')->nullOnDelete();
        });

        Schema::table('reservas', function (Blueprint $table) {
            $table->foreignId('pedido_id')->nullable()->after('mesa_id')->constrained('pedidos')->nullOnDelete();
            $table->decimal('deposito_abonado', 10, 2)->default(0)->after('deposito_aplicado_abono');
            $table->dateTime('llegada_at')->nullable()->after('deposito_abonado');
            $table->foreignId('deposito_caja_turno_id')->nullable()->after('deposito_registrado_por')->constrained('caja_turnos')->nullOnDelete();
            $table->foreignId('deposito_reembolso_caja_turno_id')->nullable()->after('deposito_caja_turno_id')->constrained('caja_turnos')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reservas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deposito_reembolso_caja_turno_id');
            $table->dropConstrainedForeignId('deposito_caja_turno_id');
            $table->dropConstrainedForeignId('pedido_id');
            $table->dropColumn(['deposito_abonado', 'llegada_at']);
        });

        Schema::table('pagos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reserva_id');
            $table->dropColumn('tipo');
        });
    }
};
