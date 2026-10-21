<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// M11 (21/10/2026): notificaciones del sistema para el personal (la campana). Una fila por persona
// que debe enterarse; `clave` evita repetir el mismo aviso a la misma persona (por ejemplo,
// «la reserva 12 empieza en 30 min» sale una sola vez).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notificaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('tipo', 40);
            $table->string('categoria', 20); // pedidos | reservas | caja | operacion | sistema
            $table->string('titulo', 120);
            $table->string('texto', 255);
            $table->string('enlace', 255)->nullable(); // pantalla del panel a la que lleva
            $table->string('clave', 120);
            $table->timestamp('leida_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['user_id', 'clave']);
            $table->index(['user_id', 'leida_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notificaciones');
    }
};
