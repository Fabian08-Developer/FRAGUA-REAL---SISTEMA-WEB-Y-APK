<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cierres_diarios', function (Blueprint $table) {
            $table->id();
            $table->date('fecha')->unique();
            $table->decimal('total_ventas', 10, 2);
            $table->decimal('total_compras', 10, 2);
            $table->decimal('total_gastos', 10, 2);
            $table->decimal('resultado_operativo', 10, 2); // ventas - compras - gastos
            $table->foreignId('cerrado_por')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cierres_diarios');
    }
};
