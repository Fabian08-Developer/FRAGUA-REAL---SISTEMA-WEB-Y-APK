<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6, tarea 1 (13/10/2026, decisión D4: Wompi): pasarela de pago con confirmación automática.
 * RF-M06-006, 007, 008, 017, RN-M06-05, RF-M07-013 a 015, RF-M05-029, 030.
 *
 * Cada intento de pago en la pasarela es una fila de `transacciones_pasarela`, enlazada al pago
 * del pedido o a la reserva cuyo depósito se paga (el depósito usa el mismo camino). Ahí quedan la
 * referencia que se le manda a la pasarela, el id de su transacción, la respuesta tal cual llegó y,
 * si se devuelve, el reembolso. Así un reintento no borra el anterior y todo se puede revisar.
 *
 * Aditiva: no cambia filas existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transacciones_pasarela', function (Blueprint $table) {
            $table->id();
            $table->string('proveedor', 20);              // wompi
            $table->string('referencia', 64)->unique();   // la que se le manda a la pasarela
            $table->morphs('pagable');                    // Pago (pedido) o Reserva (depósito)
            $table->decimal('monto', 10, 2);
            $table->string('moneda', 3)->default('COP');
            $table->string('pasarela_preferida', 20)->nullable(); // nequi | bancolombia (lo que eligió el Cliente)
            // pendiente | aprobado | rechazado | anulado | error | abandonado
            $table->string('estado', 20)->default('pendiente');
            $table->string('transaccion_id', 80)->nullable()->index(); // id de la transacción en la pasarela
            $table->string('metodo_pago', 40)->nullable();  // lo que usó de verdad: NEQUI, BANCOLOMBIA_TRANSFER, CARD…
            $table->string('estado_proveedor', 30)->nullable(); // el estado tal cual lo dice la pasarela
            $table->json('respuesta')->nullable();          // la última respuesta o evento, completo
            $table->unsignedInteger('eventos')->default(0);
            $table->dateTime('ultimo_evento_at')->nullable();
            $table->dateTime('expira_at')->nullable();
            $table->dateTime('finalizada_at')->nullable();
            // Algo no cuadró (monto distinto, aprobado después de abandonado o de rechazado a mano,
            // reserva sin mesa…): el Administrador lo revisa; el sistema no decide solo con el dinero.
            $table->boolean('requiere_revision')->default(false);
            $table->string('motivo_revision')->nullable();
            $table->foreignId('revisado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('revisado_at')->nullable();
            // Reembolso por la pasarela (RF-M06-017, RN-M05-17): solicitado | aprobado | no_soportado | fallido
            $table->string('reembolso_estado', 20)->nullable();
            $table->string('reembolso_id', 80)->nullable();
            $table->string('reembolso_mensaje')->nullable();
            $table->json('reembolso_respuesta')->nullable();
            $table->dateTime('reembolso_at')->nullable();
            $table->timestamps();

            $table->index(['estado', 'created_at']);
        });

        Schema::table('pagos', function (Blueprint $table) {
            // Cómo se aprobó o rechazó un pago en línea: 'pasarela' (evento o consulta) o 'manual'.
            $table->string('confirmado_via', 10)->nullable()->after('estado');
            $table->foreignId('confirmado_por')->nullable()->after('confirmado_via')->constrained('users')->nullOnDelete();
        });

        Schema::table('reservas', function (Blueprint $table) {
            $table->string('deposito_confirmado_via', 10)->nullable()->after('deposito_estado');
        });
    }

    public function down(): void
    {
        Schema::table('reservas', function (Blueprint $table) {
            $table->dropColumn('deposito_confirmado_via');
        });
        Schema::table('pagos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('confirmado_por');
            $table->dropColumn('confirmado_via');
        });
        Schema::dropIfExists('transacciones_pasarela');
    }
};
