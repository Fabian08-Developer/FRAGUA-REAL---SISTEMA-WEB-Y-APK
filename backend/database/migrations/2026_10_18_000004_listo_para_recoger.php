<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4, tarea 5 (13/10/2026) — RF-M07-R-012, RN-M07-R-03, HU-M07-012: aviso «listo para
 * recoger».
 *  - pedidos.listo_at / listo_por: cuándo y quién marcó que la recogida ya está lista. No es un
 *    estado nuevo (M02 y M07 no tienen LISTO): el pedido sigue en PREPARANDO hasta que el
 *    cliente lo recoge y se cumple el pago (RN-M07-R-04).
 *  - pedidos.cliente_invitado_correo: correo opcional del invitado (Checkout o pedido por
 *    teléfono), para poder avisarle. El Cliente con cuenta usa el correo de su cuenta.
 *  - pedido_avisos: cada aviso al cliente de un pedido, a qué correo, si se envió o falló, los
 *    intentos y el error (mismo esquema que reserva_avisos).
 * Aditiva: no cambia datos existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->string('cliente_invitado_correo')->nullable()->after('cliente_invitado_telefono');
            $table->timestamp('listo_at')->nullable()->after('preparando_at');
            $table->foreignId('listo_por')->nullable()->after('listo_at')->constrained('users')->nullOnDelete();
        });

        Schema::create('pedido_avisos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_id')->constrained('pedidos')->cascadeOnDelete();
            $table->string('tipo', 30); // listo_para_recoger
            $table->string('canal', 10)->default('correo'); // D3: correo en V1; sms después
            $table->string('destino');
            $table->string('estado', 12)->default('pendiente'); // pendiente | enviado | fallido
            $table->unsignedSmallInteger('intentos')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('enviado_at')->nullable();
            $table->timestamps();

            $table->index(['pedido_id', 'tipo']);
            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedido_avisos');
        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('listo_por');
            $table->dropColumn(['listo_at', 'cliente_invitado_correo']);
        });
    }
};
