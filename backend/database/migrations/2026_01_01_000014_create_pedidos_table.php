<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedidos', function (Blueprint $table) {
            $table->id();

            // --- Canal (unifica M02 + M07, ver ADR-001 en el README) ---
            $table->enum('tipo', ['local', 'domicilio', 'recogida']);
            $table->enum('origen', ['mesero', 'qr', 'cliente_web']);

            // --- Solo LOCAL ---
            $table->foreignId('mesa_id')->nullable()->constrained('mesas')->restrictOnDelete();

            // --- Cliente (registrado u invitado; domicilio/recogida) ---
            $table->foreignId('cliente_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cliente_invitado_nombre')->nullable();
            $table->string('cliente_invitado_telefono')->nullable();

            // --- Solo DOMICILIO ---
            $table->string('direccion_entrega')->nullable();
            $table->string('barrio')->nullable();
            $table->string('referencia')->nullable();
            $table->decimal('valor_domicilio', 10, 2)->nullable(); // RF-M07-012, valor vigente al crear

            // --- Estado transversal (RF-M02-T-003) ---
            $table->enum('estado', ['pendiente', 'confirmado', 'preparando', 'finalizado', 'cancelado'])
                  ->default('pendiente');

            $table->decimal('subtotal', 10, 2)->default(0);
            $table->decimal('total', 10, 2)->default(0);

            // --- Auditoría de transiciones clave ---
            $table->foreignId('confirmado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('confirmado_at')->nullable();
            $table->dateTime('preparando_at')->nullable();
            // finalizado_por: siempre el Mesero, quien registra el cobro presencial sin
            // importar el canal (local, domicilio contra entrega o recogida) — RF-M06-003, RF-M06-004
            $table->foreignId('finalizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('finalizado_at')->nullable();
            $table->foreignId('cancelado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancelado_motivo')->nullable();
            $table->dateTime('cancelado_at')->nullable();

            $table->timestamps();

            $table->index(['tipo', 'estado']); // panel de pedidos, RF-M02-T-017
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedidos');
    }
};
