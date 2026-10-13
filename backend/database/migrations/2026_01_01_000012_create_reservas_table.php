<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mesa_id')->constrained('mesas')->restrictOnDelete();
            $table->string('cliente_nombre');
            $table->string('cliente_telefono'); // RN-M05-12: obligatorio
            $table->string('cliente_correo');   // RN-M05-12: obligatorio
            $table->dateTime('fecha_hora');
            // RN-M05-16/17: pendiente_pago hasta que se pague el depósito
            $table->enum('estado', ['pendiente_pago', 'confirmada', 'cancelada', 'no_show', 'completada'])
                  ->default('pendiente_pago');
            $table->decimal('deposito_valor', 10, 2); // RF-M05-028: valor vigente al momento de crear
            $table->boolean('deposito_pagado')->default(false);
            $table->enum('deposito_metodo', ['efectivo', 'tarjeta', 'transferencia', 'en_linea'])->nullable();
            // RN-M05-16: el depósito en línea reutiliza la pasarela de M06 (Nequi/Bancolombia)
            // aplicada a la reserva en vez de a un pedido; deposito_estado sigue su ciclo
            // pendiente -> aprobado/rechazado hasta que el webhook real llegue (ver
            // README-BACKEND.md, "Pasarela de pago real").
            $table->enum('deposito_pasarela', ['nequi', 'bancolombia'])->nullable();
            $table->enum('deposito_estado', ['pendiente', 'aprobado', 'rechazado'])->nullable();
            $table->dateTime('deposito_pagado_at')->nullable();
            // RN-M05-17/18: reembolso solo si se cancela antes de la hora reservada; un
            // no-show NO se reembolsa (antes "deposito_devuelto", decisión manual del Admin).
            $table->boolean('deposito_reembolsado')->default(false);
            // RN-M05-19: si el cliente sí llega, el depósito se aplica como abono al pedido
            // de esa visita (RF-M05-015/034).
            $table->boolean('deposito_aplicado_abono')->default(false);
            // Quién registró un depósito manual (Mesero o Administrador, RN-M05-16). Nulo en
            // depósitos pagados en línea por el propio Cliente.
            $table->foreignId('deposito_registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('creado_por', ['cliente_web', 'mesero']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservas');
    }
};
