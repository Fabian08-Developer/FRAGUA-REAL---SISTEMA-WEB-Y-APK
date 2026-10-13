<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('productos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('categoria_id')->constrained('categorias')->restrictOnDelete();
            $table->string('nombre')->unique(); // RN-M04-14: único en todo el catálogo
            $table->text('descripcion')->nullable();
            $table->decimal('precio_base', 10, 2);
            $table->enum('tipo_venta', ['bebida', 'alimento', 'otro']); // RF-M04-042
            $table->boolean('disponible')->default(true);
            $table->string('imagen_url')->nullable();
            $table->boolean('activo')->default(true); // baja lógica
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('productos');
    }
};
