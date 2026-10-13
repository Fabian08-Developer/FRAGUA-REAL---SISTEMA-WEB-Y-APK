<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedido_detalle_extra', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_detalle_id')->constrained('pedido_detalles')->cascadeOnDelete();
            $table->foreignId('extra_id')->constrained('extras')->restrictOnDelete();
            $table->decimal('precio_congelado', 10, 2);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedido_detalle_extra');
    }
};
