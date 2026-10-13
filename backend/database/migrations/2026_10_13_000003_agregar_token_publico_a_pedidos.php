<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 0, tarea 4 del plan de implementación (09/10/2026): RF-M06-005, HU-M07-002/011.
// El invitado no tiene cuenta, así que no había cómo autorizarlo a pagar en línea ni a
// consultar su pedido. Se le entrega un token secreto al crear el pedido (solo en esa
// respuesta) y con él paga y consulta. Aditiva: los pedidos existentes quedan sin token.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->string('token_publico', 64)->nullable()->unique()->after('origen');
        });
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropUnique(['token_publico']);
            $table->dropColumn('token_publico');
        });
    }
};
