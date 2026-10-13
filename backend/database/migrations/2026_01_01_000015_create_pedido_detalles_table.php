<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedido_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_id')->constrained('pedidos')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos')->restrictOnDelete();
            $table->foreignId('variante_id')->nullable()->constrained('variantes')->restrictOnDelete();
            $table->foreignId('combo_id')->nullable()->constrained('combos')->restrictOnDelete();
            $table->integer('cantidad')->default(1);
            // Precio copiado al momento del pedido — protege el histórico de cambios de precio en M04
            $table->decimal('precio_unitario_congelado', 10, 2);
            $table->boolean('preparado')->default(false); // RN-PED-10
            $table->text('observaciones')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedido_detalles');
    }
};
