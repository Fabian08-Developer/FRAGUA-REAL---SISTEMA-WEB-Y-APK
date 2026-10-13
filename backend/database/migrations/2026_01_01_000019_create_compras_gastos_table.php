<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compras_gastos', function (Blueprint $table) {
            $table->id();
            $table->enum('tipo', ['compra', 'gasto']);
            $table->string('categoria')->nullable(); // Alimentos, Bebidas, Transporte, Servicios, Otros...
            $table->string('descripcion');
            $table->decimal('valor', 10, 2); // RN-M03-01: > 0
            $table->enum('metodo_pago', ['efectivo', 'transferencia', 'tarjeta', 'otro']);
            $table->string('proveedor')->nullable();
            $table->string('numero_factura')->nullable();
            $table->string('soporte_url')->nullable(); // JPG/PNG/PDF
            $table->date('fecha');
            $table->foreignId('registrado_por')->constrained('users')->restrictOnDelete();
            $table->enum('estado', ['activo', 'anulado'])->default('activo'); // RN-M03-04
            $table->string('anulado_motivo')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compras_gastos');
    }
};
